<?php
// ✅ IMPORTANTE: No debe haber NADA antes de esta línea
ob_start(); // Capturar cualquier salida no deseada

require_once '../config/db.php';
require_once '../vendor/autoload.php';
require_once '../controllers/verificar_sesion.php';
require_once '../controllers/permisos.php';
verificarPermiso('INFORMES'); // Cambia el permiso según la vista

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Color;

class InformesExamen {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene estadísticas generales para los informes
     */

    /**
     * Genera informe simple de estudiantes por competencia (para vista previa)
     */
    public function generarInformeSimple($filtros = []) {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if (!empty($filtros['fecha_inicio'])) {
                $whereClause .= " AND he.fecha_realizacion >= :fecha_inicio";
                $params[':fecha_inicio'] = $filtros['fecha_inicio'];
            }
            if (!empty($filtros['fecha_fin'])) {
                $whereClause .= " AND he.fecha_realizacion <= :fecha_fin";
                $params[':fecha_fin'] = $filtros['fecha_fin'];
            }
            if (!empty($filtros['nivel_examen'])) {
                $whereClause .= " AND he.nivel_examen = :nivel_examen";
                $params[':nivel_examen'] = $filtros['nivel_examen'];
            }
            if (!empty($filtros['programa'])) {
                $whereClause .= " AND p.programa = :programa";
                $params[':programa'] = $filtros['programa'];
            }
            if (!empty($filtros['estado'])) {
                $whereClause .= " AND he.estado = :estado";
                $params[':estado'] = $filtros['estado'];
            }

            $sql = "
                SELECT
                    p.identificacion,
                    p.nombre,
                    p.programa,
                    p.semestre,
                    hc.competencia_nombre,
                    hc.respuestas_correctas,
                    hc.total_preguntas,
                    hc.porcentaje AS porcentaje_competencia
                FROM participantes p
                INNER JOIN historial_examenes he ON p.id_participante = he.participante_id
                INNER JOIN historial_competencias hc ON he.id_historial = hc.historial_id
                $whereClause
                ORDER BY p.nombre, hc.competencia_nombre
                LIMIT 100
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error al generar informe simple: " . $e->getMessage());
        }
    }
    
    /**
     * Genera informe estructurado de estudiantes con notas por competencia (LÓGICA CORREGIDA)
     */
    public function generarInformeEstudiantesEstructurado($filtros = []) {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];
            
            // Aplicar filtros
            if (!empty($filtros['fecha_inicio'])) {
                $whereClause .= " AND he.fecha_realizacion >= :fecha_inicio";
                $params[':fecha_inicio'] = $filtros['fecha_inicio'];
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $whereClause .= " AND he.fecha_realizacion <= :fecha_fin";
                $params[':fecha_fin'] = $filtros['fecha_fin'];
            }
            
            if (!empty($filtros['nivel_examen'])) {
                $whereClause .= " AND ae.nivel_dificultad = :nivel_examen";
                $params[':nivel_examen'] = $filtros['nivel_examen'];
            }
            
            if (!empty($filtros['programa'])) {
                $whereClause .= " AND p.programa = :programa";
                $params[':programa'] = $filtros['programa'];
            }
            
            if (!empty($filtros['estado'])) {
                $whereClause .= " AND he.estado = :estado";
                $params[':estado'] = $filtros['estado'];
            }
            
            // ✅ CONSULTA COMPLETAMENTE CORREGIDA
            $sql = "
                WITH mejores_competencias AS (
                    SELECT 
                        p.id_participante,
                        hc.competencia_nombre,
                        hc.porcentaje as mejor_porcentaje_competencia,
                        ROW_NUMBER() OVER (
                            PARTITION BY p.id_participante, hc.competencia_nombre 
                            ORDER BY hc.porcentaje DESC, he.fecha_realizacion DESC
                        ) as rn
                    FROM participantes p
                    INNER JOIN historial_examenes he ON p.id_participante = he.participante_id
                    INNER JOIN historial_competencias hc ON he.id_historial = hc.historial_id
                    LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                    $whereClause
                ),
                mejores_competencias_final AS (
                    SELECT 
                        id_participante,
                        competencia_nombre,
                        mejor_porcentaje_competencia
                    FROM mejores_competencias
                    WHERE rn = 1
                ),
                datos_estudiante AS (
                    SELECT 
                        p.id_participante,
                        p.identificacion,
                        p.nombre,
                        p.programa,
                        p.semestre,
                        he.fecha_realizacion,
                        COALESCE(ae.nivel_dificultad, he.nivel_examen, 'no_asignado') as nivel_asignado,
                        he.intento_numero,
                        ROW_NUMBER() OVER (
                            PARTITION BY p.id_participante 
                            ORDER BY he.fecha_realizacion DESC, he.intento_numero DESC
                        ) as rn
                    FROM participantes p
                    INNER JOIN historial_examenes he ON p.id_participante = he.participante_id
                    LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                    $whereClause
                )
                SELECT 
                    de.id_participante,
                    de.identificacion,
                    de.nombre,
                    de.programa,
                    de.semestre,
                    de.fecha_realizacion,
                    de.nivel_asignado,
                    de.intento_numero,
                    string_agg(
                        mc.competencia_nombre || ':' || mc.mejor_porcentaje_competencia,
                        '|' ORDER BY mc.competencia_nombre
                    ) as competencias_notas
                FROM datos_estudiante de
                LEFT JOIN mejores_competencias_final mc ON de.id_participante = mc.id_participante
                WHERE de.rn = 1
                GROUP BY de.id_participante, de.identificacion, de.nombre,
                         de.programa, de.semestre, de.fecha_realizacion,
                         de.nivel_asignado, de.intento_numero
                ORDER BY de.nombre
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener todas las competencias únicas
            $competencias = $this->obtenerCompetenciasUnicas($filtros);
            
            // Estructurar datos CON LÓGICA ESTRICTA
            $datos_estructurados = [];
            
            foreach ($resultados as $fila) {
                $estudiante = [
                    'id_participante' => $fila['id_participante'],
                    'documento' => $fila['identificacion'],
                    'nombre_completo' => $fila['nombre'],
                    'programa' => $fila['programa'],
                    'semestre' => $fila['semestre'],
                    'fecha_examen' => date('d/m/Y', strtotime($fila['fecha_realizacion'])),
                    'nivel_examen' => ucfirst($fila['nivel_asignado']),
                    'intento' => $fila['intento_numero']
                ];
                
                // Inicializar todas las competencias con 0
                foreach ($competencias as $competencia) {
                    $estudiante['comp_' . $this->limpiarNombreCompetencia($competencia)] = '0.0';
                }
                
                // ✅ PROCESAR COMPETENCIAS REALES
                $suma_notas = 0;
                $count_notas = 0;
                $tiene_competencia_reprobada = false;
                $competencias_evaluadas = [];
                
                if (!empty($fila['competencias_notas'])) {
                    $notas = explode('|', $fila['competencias_notas']);
                    foreach ($notas as $nota) {
                        if (strpos($nota, ':') !== false) {
                            $partes = explode(':', $nota);
                            if (count($partes) >= 2) {
                                $nombre_comp = trim($partes[0]);
                                $porcentaje = (float)$partes[1];
                                
                                $key = 'comp_' . $this->limpiarNombreCompetencia($nombre_comp);
                                $estudiante[$key] = number_format($porcentaje, 1);
                                
                                // ✅ CONTABILIZAR PARA PROMEDIO Y ESTADO
                                if ($porcentaje >= 0) // Incluir todos los porcentajes válidos
                                {
                                    $suma_notas += $porcentaje;
                                    $count_notas++;
                                    $competencias_evaluadas[] = $porcentaje;
                                    
                                    // ✅ VERIFICAR SI TIENE ALGUNA COMPETENCIA REPROBADA
                                    if ($porcentaje < 70) {
                                        $tiene_competencia_reprobada = true;
                                    }
                                }
                            }
                        }
                    }
                }
                
                // ✅ CALCULAR PROMEDIO FINAL
                $promedio_final = $count_notas > 0 ? ($suma_notas / $count_notas) : 0;
                $estudiante['promedio_final'] = number_format($promedio_final, 1);
                
                // Si UNA sola competencia < 70 = REPROBADO automáticamente
                if ($tiene_competencia_reprobada) {
                    $estudiante['resultado'] = 'REPROBADO';
                } elseif ($promedio_final >= 70 && $count_notas > 0) {
                    $estudiante['resultado'] = 'APROBADO';
                    $estudiante['estado'] = 'APROBADO';
                } else {
                    $estudiante['resultado'] = 'REPROBADO';
                    $estudiante['estado'] = 'REPROBADO';
                }
                
                $datos_estructurados[] = $estudiante;
            }
            
            return [
                'datos' => $datos_estructurados,
                'competencias' => $competencias
            ];
            
        } catch (Exception $e) {
            throw new Exception("Error al generar informe: " . $e->getMessage());
        }
    }
    
    /**
     * Obtiene todas las competencias únicas (también corregida)
     */
    private function obtenerCompetenciasUnicas($filtros = []) {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if (!empty($filtros['nivel_examen'])) {
                $whereClause .= " AND ae.nivel_dificultad = :nivel_examen";
                $params[':nivel_examen'] = $filtros['nivel_examen'];
            }
            
            $sql = "
                SELECT DISTINCT hc.competencia_nombre
                FROM historial_competencias hc
                INNER JOIN historial_examenes he ON hc.historial_id = he.id_historial
                INNER JOIN participantes p ON he.participante_id = p.id_participante
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
                ORDER BY hc.competencia_nombre
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Limpia el nombre de competencia para usar como clave
     */
    private function limpiarNombreCompetencia($nombre) {
        $nombre = strtolower($nombre);
        $nombre = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $nombre);
        $nombre = preg_replace('/[^a-z0-9]/', '_', $nombre);
        $nombre = preg_replace('/_+/', '_', $nombre);
        return trim($nombre, '_');
    }

    /**
     * Obtiene estadísticas generales para los informes
     */
    public function obtenerEstadisticasGenerales($filtros = []) {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];

            if (!empty($filtros['fecha_inicio'])) {
                $whereClause .= " AND he.fecha_realizacion >= :fecha_inicio";
                $params[':fecha_inicio'] = $filtros['fecha_inicio'];
            }
            if (!empty($filtros['fecha_fin'])) {
                $whereClause .= " AND he.fecha_realizacion <= :fecha_fin";
                $params[':fecha_fin'] = $filtros['fecha_fin'];
            }
            if (!empty($filtros['nivel_examen'])) {
                $whereClause .= " AND (he.nivel_examen = :nivel_examen OR ae.nivel_dificultad = :nivel_examen)";
                $params[':nivel_examen'] = $filtros['nivel_examen'];
            }
            if (!empty($filtros['programa'])) {
                $whereClause .= " AND p.programa = :programa";
                $params[':programa'] = $filtros['programa'];
            }
            if (!empty($filtros['estado'])) {
                $whereClause .= " AND he.estado = :estado";
                $params[':estado'] = $filtros['estado'];
            }

            // Total estudiantes
            $sqlEstudiantes = "
                SELECT COUNT(DISTINCT p.id_participante) as total_estudiantes
                FROM participantes p
                INNER JOIN historial_examenes he ON p.id_participante = he.participante_id
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
            ";
            $stmt = $this->pdo->prepare($sqlEstudiantes);
            $stmt->execute($params);
            $total_estudiantes = (int)$stmt->fetchColumn();

            // Total examenes
            $sqlExamenes = "
                SELECT COUNT(*) as total_examenes
                FROM historial_examenes he
                INNER JOIN participantes p ON he.participante_id = p.id_participante
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
            ";
            $stmt = $this->pdo->prepare($sqlExamenes);
            $stmt->execute($params);
            $total_examenes = (int)$stmt->fetchColumn();

            // Promedio general
            $sqlPromedio = "
                SELECT AVG(hc.porcentaje) as promedio_general
                FROM historial_competencias hc
                INNER JOIN historial_examenes he ON hc.historial_id = he.id_historial
                INNER JOIN participantes p ON he.participante_id = p.id_participante
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
            ";
            $stmt = $this->pdo->prepare($sqlPromedio);
            $stmt->execute($params);
            $promedio_general = (float)$stmt->fetchColumn();

            // Aprobados y reprobados (por promedio de competencias >= 70)
            $sqlAprobados = "
                SELECT COUNT(*) as aprobados
                FROM (
                    SELECT p.id_participante, AVG(hc.porcentaje) as promedio
                    FROM participantes p
                    INNER JOIN historial_examenes he ON p.id_participante = he.participante_id
                    INNER JOIN historial_competencias hc ON he.id_historial = hc.historial_id
                    LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                    $whereClause
                    GROUP BY p.id_participante
                    HAVING AVG(hc.porcentaje) >= 70
                ) t
            ";
            $stmt = $this->pdo->prepare($sqlAprobados);
            $stmt->execute($params);
            $aprobados = (int)$stmt->fetchColumn();

            $sqlReprobados = "
                SELECT COUNT(*) as reprobados
                FROM (
                    SELECT p.id_participante, AVG(hc.porcentaje) as promedio
                    FROM participantes p
                    INNER JOIN historial_examenes he ON p.id_participante = he.participante_id
                    INNER JOIN historial_competencias hc ON he.id_historial = hc.historial_id
                    LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                    $whereClause
                    GROUP BY p.id_participante
                    HAVING AVG(hc.porcentaje) < 70
                ) t
            ";
            $stmt = $this->pdo->prepare($sqlReprobados);
            $stmt->execute($params);
            $reprobados = (int)$stmt->fetchColumn();

            return [
                'total_estudiantes' => $total_estudiantes,
                'total_examenes' => $total_examenes,
                'promedio_general' => $promedio_general,
                'aprobados' => $aprobados,
                'reprobados' => $reprobados
            ];
        } catch (Exception $e) {
            return [
                'total_estudiantes' => 0,
                'total_examenes' => 0,
                'promedio_general' => 0,
                'aprobados' => 0,
                'reprobados' => 0
            ];
        }
    }

    
    
    
    /**
     * Genera Excel PROFESIONAL - SIN CORREO Y CON COLORES PARA REPROBADAS
     */
    public function generarExcelEstructurado($datos, $competencias, $estadisticas, $filtros = []) {
        try {
            // ✅ LIMPIAR CUALQUIER SALIDA PREVIA
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // ✅ CONFIGURAR PROPIEDADES SIN CARACTERES ESPECIALES
            $spreadsheet->getProperties()
                ->setCreator("Instituto de Administracion y salud INCATEC")
                ->setLastModifiedBy("Sistema INCATEC")
                ->setTitle("Informe de Examen de ingreso INCATEC")
                ->setSubject("Resultados por Competencias")
                ->setDescription("Informe detallado de resultados de estudiantes por competencias")
                ->setKeywords("INCATEC competencias estudiantes resultados")
                ->setCategory("Reportes Academicos");

            // COLORES CORREGIDOS
            $colorAzulProfesional = '1E3A8A';
            $colorAzulClaro = 'E6F3FF';
            $colorVerde = '16A085';
            $colorRojo = 'E74C3C';
            $colorNaranja = 'F39C12';
            $colorGris = '95A5A6';

            $fila_actual = 1;
            
            // ✅ TOTAL DE COLUMNAS CORRECTO
            $total_columnas = 7 + count($competencias) + 3; // DOC+NOMBRE+PROG+SEM+FECHA+NIVEL+INTENTO + COMPETENCIAS + PROMEDIO+RESULTADO+ESTADO
            
            // =============== ENCABEZADO ===============
            $sheet->setCellValue('A1', 'INCATEC');
            $sheet->mergeCells('A1:' . $this->getColumnLetter($total_columnas) . '1');
            
            $sheet->getStyle('A1')->applyFromArray([
                'font' => [
                    'bold' => true,
                    'size' => 18,
                    'color' => ['argb' => 'FF' . $colorAzulProfesional],
                    'name' => 'Calibri'
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF' . $colorAzulClaro]
                ]
            ]);
            $sheet->getRowDimension(1)->setRowHeight(35);
            
            // Subtítulo
            $sheet->setCellValue('A2', 'INFORME DETALLADO DE RESULTADOS POR COMPETENCIAS');
            $sheet->mergeCells('A2:' . $this->getColumnLetter($total_columnas) . '2');
            $sheet->getStyle('A2')->applyFromArray([
                'font' => [
                    'bold' => true,
                    'size' => 14,
                    'color' => ['argb' => 'FF' . $colorAzulProfesional]
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            
            // Fecha
            $sheet->setCellValue('A3', 'Fecha de Generacion: ' . date('d/m/Y H:i:s'));
            $sheet->mergeCells('A3:' . $this->getColumnLetter($total_columnas) . '3');
            $sheet->getStyle('A3')->applyFromArray([
                'font' => ['size' => 11, 'italic' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            
            $fila_actual = 5;
            
            // =============== ESTADÍSTICAS ===============
            if ($estadisticas) {
                $sheet->setCellValue('A5', 'ESTADISTICAS GENERALES');
                $sheet->mergeCells('A5:' . $this->getColumnLetter($total_columnas) . '5');
                $sheet->getStyle('A5')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 13],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $colorAzulClaro]]
                ]);
                
                // Estadísticas en fila
                $stats_data = [
                    'TOTAL ESTUDIANTES' => $estadisticas['total_estudiantes'],
                    'TOTAL EXAMENES' => $estadisticas['total_examenes'],
                    'PROMEDIO GENERAL' => number_format($estadisticas['promedio_general'], 1) . '%',
                    'APROBADOS' => $estadisticas['aprobados'],
                    'REPROBADOS' => $estadisticas['reprobados']
                ];
                
                $col = 1;
                foreach ($stats_data as $label => $value) {
                    $sheet->setCellValue($this->getColumnLetter($col) . '6', $label);
                    $sheet->setCellValue($this->getColumnLetter($col) . '7', $value);
                    $sheet->getStyle($this->getColumnLetter($col) . '6')->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                    ]);
                    $sheet->getStyle($this->getColumnLetter($col) . '7')->applyFromArray([
                        'font' => ['bold' => true, 'size' => 11],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                    ]);
                    $col += 2;
                }
                
                $fila_actual = 9;
            }
            
            // =============== ENCABEZADOS DE DATOS ===============
            $headers = [
                'DOCUMENTO',
                'NOMBRE COMPLETO',
                'PROGRAMA ACADEMICO',
                'SEMESTRE',
                'FECHA EXAMEN',
                'NIVEL EXAMEN',
                'INTENTO'
            ];
            
            foreach ($competencias as $competencia) {
                $headers[] = strtoupper($competencia) . ' (%)';
            }
            
            $headers[] = 'PROMEDIO FINAL (%)';
            $headers[] = 'RESULTADO';
            $headers[] = 'ESTADO ACADEMICO';
            
            // Escribir encabezados
            foreach ($headers as $col_index => $header) {
                $col_letter = $this->getColumnLetter($col_index + 1);
                $sheet->setCellValue($col_letter . $fila_actual, $header);
            }
            
            // Estilo de encabezados
            $header_range = 'A' . $fila_actual . ':' . $this->getColumnLetter(count($headers)) . $fila_actual;
            $sheet->getStyle($header_range)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'size' => 10,
                    'color' => ['argb' => 'FFFFFFFF']
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF' . $colorAzulProfesional]
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => 'FFFFFFFF']
                    ]
                ]
            ]);
            
            $sheet->getRowDimension($fila_actual)->setRowHeight(25);
            $fila_actual++;
            
            // =============== DATOS DE ESTUDIANTES ===============
            foreach ($datos as $row_index => $estudiante) {
                $fila_datos = [
                    $estudiante['documento'],
                    $estudiante['nombre_completo'],
                    $estudiante['programa'],
                    $estudiante['semestre'],
                    $estudiante['fecha_examen'],
                    $estudiante['nivel_examen'],
                    $estudiante['intento']
                ];
                
                // Agregar competencias
                foreach ($competencias as $competencia) {
                    $key = 'comp_' . $this->limpiarNombreCompetencia($competencia);
                    $nota = isset($estudiante[$key]) ? $estudiante[$key] : '0.0';
                    $fila_datos[] = $nota;
                }
                
                $fila_datos[] = $estudiante['promedio_final'];
                $fila_datos[] = $estudiante['resultado'];
                $fila_datos[] = isset($estudiante['estado']) ? $estudiante['estado'] : $estudiante['resultado'];
                
                // Escribir datos
                foreach ($fila_datos as $col_index => $valor) {
                    $col_letter = $this->getColumnLetter($col_index + 1);
                    $sheet->setCellValue($col_letter . $fila_actual, $valor);
                }
                
                // Estilo de fila
                $bg_color = ($row_index % 2 == 0) ? 'FFFFFFFF' : 'FFF8F9FA';
                $row_range = 'A' . $fila_actual . ':' . $this->getColumnLetter(count($fila_datos)) . $fila_actual;
                
                $sheet->getStyle($row_range)->applyFromArray([
                    'font' => ['size' => 9],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg_color]],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['argb' => 'FFE0E0E0']
                        ]
                    ]
                ]);
                
                // ✅ COLOREAR COMPETENCIAS REPROBADAS
                for ($i = 7; $i < 7 + count($competencias); $i++) {
                    $col_letter = $this->getColumnLetter($i + 1);
                    $col_index_competencia = $i - 7;
                    $competencia_nombre = $competencias[$col_index_competencia];
                    $key = 'comp_' . $this->limpiarNombreCompetencia($competencia_nombre);
                    $nota_competencia = (float)($estudiante[$key] ?? 0);

                    if ($nota_competencia < 70 && $nota_competencia > 0) {
                        $sheet->getStyle($col_letter . $fila_actual)->applyFromArray([
                            'font' => ['bold' => true, 'color' => ['argb' => 'FF' . $colorRojo]],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                        ]);
                    } else {
                        $sheet->getStyle($col_letter . $fila_actual)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                }
                
                // Centrar promedio
                $promedio_col = $this->getColumnLetter(7 + count($competencias) + 1);
                $sheet->getStyle($promedio_col . $fila_actual)->applyFromArray([
                    'font' => ['bold' => true],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                ]);
                
                // Colorear resultado
                $resultado_col = $this->getColumnLetter(7 + count($competencias) + 2);
                $estado_col = $this->getColumnLetter(7 + count($competencias) + 3);
                
                $promedio = (float)$estudiante['promedio_final'];
                $color_resultado = ($promedio >= 70) ? $colorVerde : $colorRojo;
                
                $sheet->getStyle($resultado_col . $fila_actual . ':' . $estado_col . $fila_actual)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $color_resultado]]
                ]);
                
                $fila_actual++;
            }
            
            // =============== AJUSTAR ANCHOS ===============
            $anchos = [
                'A' => 15, 'B' => 35, 'C' => 30, 'D' => 12, 'E' => 15, 'F' => 15, 'G' => 10
            ];
            
            foreach ($anchos as $col => $ancho) {
                $sheet->getColumnDimension($col)->setWidth($ancho);
            }
            
            // Anchos competencias
            for ($i = 7; $i < 7 + count($competencias); $i++) {
                $col_letter = $this->getColumnLetter($i + 1);
                $sheet->getColumnDimension($col_letter)->setWidth(15);
            }
            
            // Anchos finales
            $sheet->getColumnDimension($this->getColumnLetter(7 + count($competencias) + 1))->setWidth(18);
            $sheet->getColumnDimension($this->getColumnLetter(7 + count($competencias) + 2))->setWidth(15);
            $sheet->getColumnDimension($this->getColumnLetter(7 + count($competencias) + 3))->setWidth(18);
            
            // =============== CONFIGURACIONES FINALES ===============
            $sheet->setTitle('Competencias INCATEC');
            $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
            
            // ✅ NOMBRE DE ARCHIVO SEGURO (SIN CARACTERES ESPECIALES)
            $fecha_hora = date('Y-m-d_H-i-s');
            $filename = "Informe_Examen_Ingreso_INCATEC_{$fecha_hora}.xlsx";
            
            // ✅ HEADERS HTTP CORRECTOS
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Cache-Control: max-age=1');
            header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            header('Cache-Control: cache, must-revalidate');
            header('Pragma: public');
            
            // ✅ GENERAR Y ENVIAR ARCHIVO
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit();
            
        } catch (Exception $e) {
            throw new Exception("Error al generar Excel: " . $e->getMessage());
        }
    }
    
    /**
     * Genera informe de estudiantes sin examen realizado
     */
    public function generarInformeEstudiantesSinExamen($filtros = []) {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];
            
            // Aplicar filtros
            if (!empty($filtros['fecha_inicio'])) {
                $whereClause .= " AND ae.fecha_asignacion >= :fecha_inicio";
                $params[':fecha_inicio'] = $filtros['fecha_inicio'];
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $whereClause .= " AND ae.fecha_asignacion <= :fecha_fin";
                $params[':fecha_fin'] = $filtros['fecha_fin'];
            }
            
            if (!empty($filtros['nivel_examen'])) {
                $whereClause .= " AND ae.nivel_dificultad = :nivel_examen";
                $params[':nivel_examen'] = $filtros['nivel_examen'];
            }
            
            if (!empty($filtros['programa'])) {
                $whereClause .= " AND p.programa = :programa";
                $params[':programa'] = $filtros['programa'];
            }
            
            $sql = "
                SELECT 
                    p.id_participante,
                    p.identificacion,
                    p.nombre,
                    p.correo,
                    p.programa,
                    p.semestre,
                    p.fecha_registro,
                    ae.nivel_dificultad,
                    ae.fecha_asignacion,
                    CASE 
                        WHEN ae.fecha_asignacion IS NULL THEN 'SIN ASIGNAR'
                        WHEN NOT EXISTS (
                            SELECT 1 FROM respuestas r WHERE r.id_participante = p.id_participante
                        ) THEN 'NO INICIADO'
                        WHEN EXISTS (
                            SELECT 1 FROM respuestas r WHERE r.id_participante = p.id_participante
                        ) AND NOT EXISTS (
                            SELECT 1 FROM resultados res WHERE res.participante_id = p.id_participante
                        ) THEN 'INICIADO SIN TERMINAR'
                        ELSE 'COMPLETADO'
                    END as estado_examen,
                    (SELECT COUNT(*) FROM respuestas r WHERE r.id_participante = p.id_participante) as respuestas_parciales,
                    CASE 
                        WHEN ae.fecha_asignacion IS NOT NULL THEN 
                            EXTRACT(DAYS FROM (CURRENT_TIMESTAMP - ae.fecha_asignacion))
                        ELSE NULL
                    END as dias_desde_asignacion
                FROM participantes p
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
                AND NOT EXISTS (
                    SELECT 1 FROM resultados res WHERE res.participante_id = p.id_participante
                )
                ORDER BY ae.fecha_asignacion DESC, p.nombre ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            throw new Exception("Error al generar informe de estudiantes sin examen: " . $e->getMessage());
        }
    }

    /**
     * Obtiene estadísticas de estudiantes sin examen
     */
    public function obtenerEstadisticasEstudiantesSinExamen($filtros = []) {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if (!empty($filtros['fecha_inicio'])) {
                $whereClause .= " AND ae.fecha_asignacion >= :fecha_inicio";
                $params[':fecha_inicio'] = $filtros['fecha_inicio'];
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $whereClause .= " AND ae.fecha_asignacion <= :fecha_fin";
                $params[':fecha_fin'] = $filtros['fecha_fin'];
            }
            
            if (!empty($filtros['nivel_examen'])) {
                $whereClause .= " AND ae.nivel_dificultad = :nivel_examen";
                $params[':nivel_examen'] = $filtros['nivel_examen'];
            }
            
            if (!empty($filtros['programa'])) {
                $whereClause .= " AND p.programa = :programa";
                $params[':programa'] = $filtros['programa'];
            }
            
            // Total estudiantes con examen asignado
            $sqlTotal = "
                SELECT COUNT(*) as total
                FROM participantes p
                INNER JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
            ";
            $stmt = $this->pdo->prepare($sqlTotal);
            $stmt->execute($params);
            $total_asignados = (int)$stmt->fetchColumn();
            
            // Estudiantes sin examen realizado
            $sqlSinExamen = "
                SELECT COUNT(*) as total
                FROM participantes p
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
                AND NOT EXISTS (
                    SELECT 1 FROM resultados res WHERE res.participante_id = p.id_participante
                )
            ";
            $stmt = $this->pdo->prepare($sqlSinExamen);
            $stmt->execute($params);
            $sin_examen = (int)$stmt->fetchColumn();
            
            // Estudiantes que iniciaron pero no terminaron
            $sqlIniciados = "
                SELECT COUNT(*) as total
                FROM participantes p
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
                AND EXISTS (
                    SELECT 1 FROM respuestas r WHERE r.id_participante = p.id_participante
                )
                AND NOT EXISTS (
                    SELECT 1 FROM resultados res WHERE res.participante_id = p.id_participante
                )
            ";
            $stmt = $this->pdo->prepare($sqlIniciados);
            $stmt->execute($params);
            $iniciados_sin_terminar = (int)$stmt->fetchColumn();
            
            // Promedio de días desde asignación
            $sqlPromedioDias = "
                SELECT AVG(EXTRACT(DAYS FROM (CURRENT_TIMESTAMP - ae.fecha_asignacion))) as promedio_dias
                FROM participantes p
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                $whereClause
                AND NOT EXISTS (
                    SELECT 1 FROM resultados res WHERE res.participante_id = p.id_participante
                )
                AND ae.fecha_asignacion IS NOT NULL
            ";
            $stmt = $this->pdo->prepare($sqlPromedioDias);
            $stmt->execute($params);
            $promedio_dias = (float)$stmt->fetchColumn();
            
            return [
                'total_asignados' => $total_asignados,
                'sin_examen' => $sin_examen,
                'iniciados_sin_terminar' => $iniciados_sin_terminar,
                'no_iniciados' => $sin_examen - $iniciados_sin_terminar,
                'promedio_dias_asignacion' => $promedio_dias,
                'porcentaje_pendientes' => $total_asignados > 0 ? ($sin_examen / $total_asignados) * 100 : 0
            ];
            
        } catch (Exception $e) {
            return [
                'total_asignados' => 0,
                'sin_examen' => 0,
                'iniciados_sin_terminar' => 0,
                'no_iniciados' => 0,
                'promedio_dias_asignacion' => 0,
                'porcentaje_pendientes' => 0
            ];
        }
    }

    /**
     * Genera Excel para estudiantes sin examen
     */
    public function generarExcelEstudiantesSinExamen($datos, $estadisticas, $filtros = []) {
        try {
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Configurar propiedades
            $spreadsheet->getProperties()
                ->setCreator("Instituto de Administracion y salud INCATEC")
                ->setTitle("Informe de Estudiantes Sin Examen - INCATEC")
                ->setSubject("Estudiantes Pendientes de Examen")
                ->setDescription("Informe de estudiantes que tienen asignado el examen pero no lo han realizado");
        
            // Colores
            $colorAzul = '1E3A8A';
            $colorRojo = 'E74C3C';
            $colorNaranja = 'F39C12';
            $colorGris = '95A5A6';
            $colorAzulClaro = 'E6F3FF';
        
            $fila_actual = 1;
        
            // Encabezado principal
            $sheet->setCellValue('A1', 'INCATEC - ESTUDIANTES SIN EXAMEN REALIZADO');
            $sheet->mergeCells('A1:K1');
            $sheet->getStyle('A1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FF' . $colorAzul]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $colorAzulClaro]]
            ]);
        
            // Fecha
            $sheet->setCellValue('A2', 'Fecha de Generacion: ' . date('d/m/Y H:i:s'));
            $sheet->mergeCells('A2:K2');
            $sheet->getStyle('A2')->applyFromArray([
                'font' => ['italic' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
        
            $fila_actual = 4;
        
            // Estadísticas
            if ($estadisticas) {
                $sheet->setCellValue('A4', 'ESTADISTICAS');
                $sheet->mergeCells('A4:K4');
                $sheet->getStyle('A4')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $colorAzulClaro]]
                ]);
                
                $stats = [
                    'A5' => ['Total Asignados', $estadisticas['total_asignados']],
                    'C5' => ['Sin Examen', $estadisticas['sin_examen']],
                    'E5' => ['No Iniciados', $estadisticas['no_iniciados']],
                    'G5' => ['Iniciados sin Terminar', $estadisticas['iniciados_sin_terminar']],
                    'I5' => ['% Pendientes', number_format($estadisticas['porcentaje_pendientes'], 1) . '%'],
                    'K5' => ['Promedio Días', number_format($estadisticas['promedio_dias_asignacion'], 0)]
                ];
                
                foreach ($stats as $celda => $data) {
                    $sheet->setCellValue($celda, $data[0]);
                    $sheet->setCellValue(substr($celda, 0, 1) . '6', $data[1]);
                    $sheet->getStyle($celda)->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                    ]);
                    $sheet->getStyle(substr($celda, 0, 1) . '6')->applyFromArray([
                        'font' => ['bold' => true, 'size' => 10],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                    ]);
                }
                
                $fila_actual = 8;
            }
            
            // Encabezados de datos
            $headers = [
                'DOCUMENTO',
                'NOMBRE COMPLETO',
                'CORREO',
                'PROGRAMA',
                'SEMESTRE',
                'FECHA REGISTRO',
                'NIVEL ASIGNADO',
                'FECHA ASIGNACION',
                'DIAS DESDE ASIGNACION',
                'ESTADO',
                'RESPUESTAS PARCIALES'
            ];
            
            foreach ($headers as $col_index => $header) {
                $col_letter = $this->getColumnLetter($col_index + 1);
                $sheet->setCellValue($col_letter . $fila_actual, $header);
            }
            
            // Estilo de encabezados
            $header_range = 'A' . $fila_actual . ':K' . $fila_actual;
            $sheet->getStyle($header_range)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $colorAzul]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
            
            $fila_actual++;
            
            // Datos
            foreach ($datos as $row_index => $estudiante) {
                $fila_datos = [
                    $estudiante['identificacion'],
                    $estudiante['nombre'],
                    $estudiante['correo'],
                    $estudiante['programa'] ?? 'N/A',
                    $estudiante['semestre'] ?? 'N/A',
                    date('d/m/Y', strtotime($estudiante['fecha_registro'])),
                    $estudiante['nivel_dificultad'] ? ucfirst($estudiante['nivel_dificultad']) : 'Sin asignar',
                    $estudiante['fecha_asignacion'] ? date('d/m/Y', strtotime($estudiante['fecha_asignacion'])) : 'N/A',
                    $estudiante['dias_desde_asignacion'] ?? 'N/A',
                    $estudiante['estado_examen'],
                    $estudiante['respuestas_parciales']
                ];
                
                foreach ($fila_datos as $col_index => $valor) {
                    $col_letter = $this->getColumnLetter($col_index + 1);
                    $sheet->setCellValue($col_letter . $fila_actual, $valor);
                }
                
                // Colorear según estado
                $color_fondo = 'FFFFFFFF';
                if ($estudiante['estado_examen'] === 'NO INICIADO') {
                    $color_fondo = 'FFFFEAA7'; // Naranja claro
                } elseif ($estudiante['estado_examen'] === 'INICIADO SIN TERMINAR') {
                    $color_fondo = 'FFFDCB6E'; // Amarillo
                } elseif ($estudiante['estado_examen'] === 'SIN ASIGNAR') {
                    $color_fondo = 'FFFF6B6B'; // Rojo claro
                }
                
                $row_range = 'A' . $fila_actual . ':K' . $fila_actual;
                $sheet->getStyle($row_range)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $color_fondo]],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE0E0E0']]]
                ]);
                
                $fila_actual++;
            }
            
            // Ajustar anchos
            $anchos = ['A' => 15, 'B' => 30, 'C' => 25, 'D' => 25, 'E' => 12, 'F' => 15, 'G' => 15, 'H' => 15, 'I' => 20, 'J' => 20, 'K' => 18];
            foreach ($anchos as $col => $ancho) {
                $sheet->getColumnDimension($col)->setWidth($ancho);
            }
            
            // Configuración final
            $sheet->setTitle('Estudiantes Sin Examen');
            $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
            
            // Headers y descarga
            $fecha_hora = date('Y-m-d_H-i-s');
            $filename = "Estudiantes_Sin_Examen_INCATEC_{$fecha_hora}.xlsx";
            
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit();
            
        } catch (Exception $e) {
            throw new Exception("Error al generar Excel: " . $e->getMessage());
        }
    }
    
    /**
     * ✅ FUNCIÓN AUXILIAR CORREGIDA
     */
    private function getColumnLetter($columnNumber) {
        $columnLetter = '';
        while ($columnNumber > 0) {
            $columnNumber--;
            $columnLetter = chr(65 + ($columnNumber % 26)) . $columnLetter;
            $columnNumber = intval($columnNumber / 26);
        }
        return $columnLetter;
    }
}

// ✅ PROCESAR EXCEL CON MANEJO DE ERRORES
$informes = new InformesExamen($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'generar_excel') {
    try {
        $filtros = array_filter([
            'fecha_inicio' => $_POST['fecha_inicio'] ?? '',
            'fecha_fin' => $_POST['fecha_fin'] ?? '',
            'nivel_examen' => $_POST['nivel_examen'] ?? '',
            'programa' => $_POST['programa'] ?? '',
            'estado' => $_POST['estado'] ?? ''
        ]);
        
        $resultado = $informes->generarInformeEstudiantesEstructurado($filtros);
        $datos = $resultado['datos'];
        $competencias = $resultado['competencias'];
        $estadisticas = $informes->obtenerEstadisticasGenerales($filtros);
        
        if (empty($datos)) {
            throw new Exception("No se encontraron datos para los filtros especificados.");
        }
        
        // ✅ LIMPIAR BUFFER ANTES DE GENERAR EXCEL
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $informes->generarExcelEstructurado($datos, $competencias, $estadisticas, $filtros);
        
    } catch (Exception $e) {
        $error = "Error al generar Excel: " . $e->getMessage();
        // Log del error
        error_log($error);
    }
}

// ✅ RESTO DEL CÓDIGO HTML (continúa igual)
require_once '../includes/header.php';

// Procesar vista previa
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'vista_previa':
            try {
                $filtros = [
                    'fecha_inicio' => $_POST['fecha_inicio'] ?? '',
                    'fecha_fin' => $_POST['fecha_fin'] ?? '',
                    'nivel_examen' => $_POST['nivel_examen'] ?? '',
                    'programa' => $_POST['programa'] ?? '',
                    'estado' => $_POST['estado'] ?? ''
                ];
                
                $filtros = array_filter($filtros);
                $datos = $informes->generarInformeSimple($filtros);
                $estadisticas = $informes->obtenerEstadisticasGenerales($filtros);
                
            } catch (Exception $e) {
                $error_vista = $e->getMessage();
            }
            break;
        
        case 'generar_excel_sin_examen':
            try {
                $filtros = array_filter([
                    'fecha_inicio' => $_POST['fecha_inicio'] ?? '',
                    'fecha_fin' => $_POST['fecha_fin'] ?? '',
                    'nivel_examen' => $_POST['nivel_examen'] ?? '',
                    'programa' => $_POST['programa'] ?? ''
                ]);
                
                $datos = $informes->generarInformeEstudiantesSinExamen($filtros);
                $estadisticas = $informes->obtenerEstadisticasEstudiantesSinExamen($filtros);
                
                if (empty($datos)) {
                    throw new Exception("No se encontraron estudiantes sin examen para los filtros especificados.");
                }
                
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                $informes->generarExcelEstudiantesSinExamen($datos, $estadisticas, $filtros);
                
            } catch (Exception $e) {
                $error = "Error al generar Excel: " . $e->getMessage();
                error_log($error);
            }
            break;
    }
}

// Obtener datos para filtros
try {
    $stmt = $pdo->query("SELECT DISTINCT programa FROM participantes WHERE programa IS NOT NULL ORDER BY programa");
    $programas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT DISTINCT nivel_examen FROM historial_examenes ORDER BY nivel_examen");
    $niveles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT DISTINCT estado FROM historial_examenes ORDER BY estado");
    $estados = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    $error_filtros = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informes Excel Examen de Ingreso - INCATEC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ✅ ESTILOS PROFESIONALES APLICADOS A INFORMES */
        :root {
            --azul-incatec: #2196F3;
            --verde-success: #28a745;
            --rojo-danger: #dc3545;
            --naranja-warning: #ff9800;
            --gris-suave: #f8f9fa;
            --gris-oscuro: #333;
            --sombra-suave: 0 2px 4px rgba(0,0,0,0.1);
            --sombra-hover: 0 4px 8px rgba(0,0,0,0.15);
            --sombra-lg: 0 8px 24px rgba(0,0,0,0.12);
            --border-radius: 8px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--gris-oscuro);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* Header profesional */
        .header-professional {
            background: linear-gradient(135deg, var(--azul-incatec) 0%, #1976d2 100%);
            color: white;
            padding: 3rem 0;
            box-shadow: var(--sombra-lg);
            position: relative;
            overflow: hidden;
        }

        .header-professional::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="1"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
            opacity: 0.3;
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .page-title {
            font-size: 2.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .page-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 400;
        }

        /* Secciones de contenido */
        .content-section {
            margin: 2rem 0;
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .content-section:nth-child(2) { animation-delay: 0.1s; }
        .content-section:nth-child(3) { animation-delay: 0.2s; }
        .content-section:nth-child(4) { animation-delay: 0.3s; }
        .content-section:nth-child(5) { animation-delay: 0.4s; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Cards profesionales */
        .card-professional {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--sombra-suave);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card-professional:hover {
            transform: translateY(-2px);
            box-shadow: var(--sombra-hover);
        }

        .card-header-professional {
            background: linear-gradient(135deg, #ffffff 0%, var(--gris-suave) 100%);
            padding: 1.5rem 2rem;
            border-bottom: 2px solid #e9ecef;
            position: relative;
        }

        .card-header-professional::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(135deg, var(--azul-incatec), var(--verde-success));
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gris-oscuro);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-body-professional {
            padding: 2rem;
        }

        /* Filtros mejorados */
        .filter-section {
            background: var(--gris-suave);
            padding: 24px;
            border-radius: var(--border-radius);
            border: 1px solid #dee2e6;
            margin-bottom: 24px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-group-professional {
            position: relative;
        }

        .form-label-professional {
            display: block;
            font-weight: 600;
            color: var(--gris-oscuro);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-control-professional {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid #e9ecef;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
            font-family: inherit;
            color: var(--gris-oscuro);
        }

        .form-control-professional:focus {
            outline: none;
            border-color: var(--azul-incatec);
            box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.1);
            transform: translateY(-1px);
        }

        .form-control-professional:hover {
            border-color: #adb5bd;
        }

        /* Botones profesionales */
        .btn-professional {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            position: relative;
            overflow: hidden;
            font-family: inherit;
        }

        .btn-professional::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn-professional:hover::before {
            left: 100%;
        }

        .btn-professional:hover {
            transform: translateY(-2px);
            box-shadow: var(--sombra-hover);
        }

        .btn-success-professional {
            background: linear-gradient(135deg, var(--verde-success), #198754);
            color: white;
            box-shadow: var(--sombra-suave);
        }

        .btn-info-professional {
            background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
            color: white;
            box-shadow: var(--sombra-suave);
        }

        .btn-secondary-professional {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            box-shadow: var(--sombra-suave);
        }

        .btn-danger-professional {
            background: linear-gradient(135deg, var(--rojo-danger), #c82333);
            color: white;
            box-shadow: var(--sombra-suave);
        }

        /* Estadísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            box-shadow: var(--sombra-suave);
            border: 1px solid #e9ecef;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--azul-incatec), var(--verde-success));
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--sombra-lg);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--azul-incatec);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.95rem;
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Tablas profesionales */
        .table-professional {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--sombra-suave);
        }

        .table-professional th {
            background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
            color: white;
            padding: 1rem 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .table-professional td {
            padding: 0.875rem 0.75rem;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .table-professional tbody tr:hover {
            background: rgba(33, 150, 243, 0.05);
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--sombra-suave);
        }

        /* Badges profesionales */
        .badge-professional {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .badge-success {
            background: var(--verde-success);
            color: white;
        }

        .badge-danger {
            background: var(--rojo-danger);
            color: white;
        }

        .badge-warning {
            background: var(--naranja-warning);
            color: white;
        }

        /* Alertas profesionales */
        .alert-professional {
            border: none;
            border-radius: var(--border-radius);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
        }

        .alert-danger-professional {
            background: #f8d7da;
            border-left-color: var(--rojo-danger);
            color: #721c24;
        }

        .alert-info-professional {
            background: #cce7ff;
            border-left-color: var(--azul-incatec);
            color: #0c5460;
        }

        /* Banner informativo */
        .info-banner {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 2px solid var(--azul-incatec);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            text-align: center;
        }

        .info-banner h4 {
            color: #1976d2;
            margin-bottom: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .info-banner p {
            margin-bottom: 0.5rem;
            color: #1565c0;
        }

        .info-banner .mb-0 {
            margin-bottom: 0;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        /* Contenedor de acciones */
        .actions-container {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid #e9ecef;
        }

        /* Estados de carga */
        .btn-loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn-loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .actions-container {
                justify-content: center;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .card-header-professional,
            .card-body-professional {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .btn-professional {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
        }

        /* Efectos adicionales */
        .card-professional {
            position: relative;
            overflow: hidden;
        }

        .card-professional::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(33, 150, 243, 0.05), transparent);
            transition: left 0.6s ease;
        }

        .card-professional:hover::after {
            left: 100%;
        }

        /* Tooltips mejorados */
        [title]:hover {
            cursor: help;
        }

        /* Loading states */
        .btn-professional:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-professional:disabled:hover {
            transform: none;
            box-shadow: var(--sombra-suave);
        }
    </style>
</head>
<body>
    <div class="header-professional">
        <div class="container">
            <div class="header-content text-center">
                <h1 class="page-title">
                    <i class="fas fa-chart-line"></i>
                    Generador de Informes Excel
                </h1>

            </div>
        </div>
    </div>

    <div class="container">
        <?php if (isset($error)): ?>
            <div class="content-section">
                <div class="alert-professional alert-danger-professional">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="content-section">
            <div class="info-banner">
                <h4>
                    <i class="fas fa-file-excel"></i>
                    Formato del Informe Excel
                </h4>
                <p><strong>Estructura:</strong> DOCUMENTO | NOMBRE | PROGRAMA | SEMESTRE | COMPETENCIAS | PROMEDIO | RESULTADO</p>
                <p class="mb-0"><small>🔎</small></p>
            </div>
        </div>

        <div class="content-section">
            <div class="card-professional">
                <div class="card-header-professional">
                    <h3 class="card-title">
                        <i class="fas fa-sliders-h"></i>
                        Configuración de Filtros
                    </h3>
                </div>
                <div class="card-body-professional">
                    <form method="POST" id="formInforme" class="filter-section">
                        <div class="filters-grid">
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-calendar-alt"></i>
                                    Fecha Inicio
                                </label>
                                <input type="date" class="form-control-professional" name="fecha_inicio" 
                                       value="<?php echo $_POST['fecha_inicio'] ?? ''; ?>">
                            </div>
                            
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-calendar-alt"></i>
                                    Fecha Fin
                                </label>
                                <input type="date" class="form-control-professional" name="fecha_fin"
                                       value="<?php echo $_POST['fecha_fin'] ?? ''; ?>">
                            </div>
                            
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-layer-group"></i>
                                    Nivel de Examen
                                </label>
                                <select class="form-control-professional" name="nivel_examen">
                                    <option value="">Todos los niveles</option>
                                    <?php if (isset($niveles)): ?>
                                        <?php foreach ($niveles as $nivel): ?>
                                            <option value="<?php echo htmlspecialchars($nivel); ?>"
                                                    <?php echo (($_POST['nivel_examen'] ?? '') === $nivel) ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($nivel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-graduation-cap"></i>
                                    Programa
                                </label>
                                <select class="form-control-professional" name="programa">
                                    <option value="">Todos los programas</option>
                                    <?php if (isset($programas)): ?>
                                        <?php foreach ($programas as $programa): ?>
                                            <option value="<?php echo htmlspecialchars($programa); ?>"
                                                    <?php echo (($_POST['programa'] ?? '') === $programa) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($programa); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-flag"></i>
                                    Estado
                                </label>
                                <select class="form-control-professional" name="estado">
                                    <option value="">Todos los estados</option>
                                    <?php if (isset($estados)): ?>
                                        <?php foreach ($estados as $estado): ?>
                                            <option value="<?php echo htmlspecialchars($estado); ?>"
                                                    <?php echo (($_POST['estado'] ?? '') === $estado) ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($estado); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="actions-container">
                            <button type="submit" name="accion" value="vista_previa" class="btn-professional btn-info-professional">
                                <i class="fas fa-eye"></i>
                                Vista Previa
                            </button>
                            
                            <button type="submit" name="accion" value="generar_excel" class="btn-professional btn-success-professional">
                                <i class="fas fa-download"></i>
                                Descargar Excel
                            </button>
                            
                            <a href="?" class="btn-professional btn-secondary-professional">
                                <i class="fas fa-refresh"></i>
                                Limpiar Filtros
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (isset($estadisticas)): ?>
            <div class="content-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['total_estudiantes']; ?></div>
                        <div class="stat-label">
                            <i class="fas fa-users"></i>
                            Estudiantes
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['total_examenes']; ?></div>
                        <div class="stat-label">
                            <i class="fas fa-file-alt"></i>
                            Exámenes
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($estadisticas['promedio_general'], 1); ?>%</div>
                        <div class="stat-label">
                            <i class="fas fa-chart-line"></i>
                            Promedio
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['aprobados']; ?></div>
                        <div class="stat-label">
                            <i class="fas fa-check-circle"></i>
                            Aprobados
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['reprobados']; ?></div>
                        <div class="stat-label">
                            <i class="fas fa-times-circle"></i>
                            Reprobados
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($datos) && !empty($datos)): ?>
            <div class="content-section">
                <div class="card-professional">
                    <div class="card-header-professional">
                        <h3 class="card-title">
                            <i class="fas fa-table"></i>
                            Vista Previa de Datos (Primeros 100 registros)
                        </h3>
                    </div>
                    <div class="card-body-professional">
                        <div class="table-container">
                            <table class="table-professional">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-id-card"></i> Documento</th>
                                        <th><i class="fas fa-user"></i> Nombre</th>
                                        <th><i class="fas fa-graduation-cap"></i> Programa</th>
                                        <th><i class="fas fa-calendar"></i> Semestre</th>
                                        <th><i class="fas fa-brain"></i> Competencia</th>
                                        <th><i class="fas fa-check"></i> Correctas</th>
                                        <th><i class="fas fa-question"></i> Total</th>
                                        <th><i class="fas fa-percent"></i> Porcentaje</th>
                                        <th><i class="fas fa-flag"></i> Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($datos as $fila): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($fila['identificacion']); ?></td>
                                            <td title="<?php echo htmlspecialchars($fila['nombre']); ?>">
                                                <?php echo htmlspecialchars(strlen($fila['nombre']) > 25 ? substr($fila['nombre'], 0, 25) . '...' : $fila['nombre']); ?>
                                            </td>
                                            <td title="<?php echo htmlspecialchars($fila['programa']); ?>">
                                                <?php echo htmlspecialchars(strlen($fila['programa']) > 20 ? substr($fila['programa'], 0, 20) . '...' : $fila['programa']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($fila['semestre']); ?></td>
                                            <td><?php echo htmlspecialchars($fila['competencia_nombre']); ?></td>
                                            <td><?php echo $fila['respuestas_correctas']; ?></td>
                                            <td><?php echo $fila['total_preguntas']; ?></td>
                                            <td><?php echo number_format($fila['porcentaje_competencia'], 1); ?>%</td>
                                            <td>
                                                <?php if ($fila['porcentaje_competencia'] >= 70): ?>
                                                    <span class="badge-professional badge-success">APROBADO</span>
                                                <?php elseif ($fila['porcentaje_competencia'] >= 60): ?>
                                                    <span class="badge-professional badge-warning">REHABILITADO</span>
                                                <?php else: ?>
                                                    <span class="badge-professional badge-danger">REPROBADO</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Nueva sección para estudiantes sin examen -->
        <div class="content-section">
            <div class="card-professional">
                <div class="card-header-professional">
                    <h3 class="card-title">
                        <i class="fas fa-user-clock"></i>
                        Informe de Estudiantes Sin Examen
                    </h3>
                </div>
                <div class="card-body-professional">
                    <div class="info-banner" style="background: linear-gradient(135deg, #fff3e0, #ffcc80); border-color: #f57c00;">
                        <h4 style="color: #e65100;">
                            <i class="fas fa-exclamation-triangle"></i>
                            Estudiantes Pendientes de Examen
                        </h4>
                        <p style="color: #ef6c00;"><strong>Incluye:</strong> Estudiantes con examen asignado pero no realizado</p>
                        <p style="color: #ef6c00;"><strong>Estados:</strong> Sin iniciar, Iniciado sin terminar, Sin asignar</p>
                        <p class="mb-0" style="color: #ef6c00;">📋 Ideal para seguimiento y recordatorios</p>
                    </div>
                    
                    <form method="POST" id="formInformeSinExamen" class="filter-section">
                        <div class="filters-grid">
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-calendar-alt"></i>
                                    Fecha Asignación Inicio
                                </label>
                                <input type="date" class="form-control-professional" name="fecha_inicio" 
                                       value="<?php echo $_POST['fecha_inicio'] ?? ''; ?>">
                            </div>
                            
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-calendar-alt"></i>
                                    Fecha Asignación Fin
                                </label>
                                <input type="date" class="form-control-professional" name="fecha_fin"
                                       value="<?php echo $_POST['fecha_fin'] ?? ''; ?>">
                            </div>
                            
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-layer-group"></i>
                                    Nivel de Examen
                                </label>
                                <select class="form-control-professional" name="nivel_examen">
                                    <option value="">Todos los niveles</option>
                                    <?php if (isset($niveles)): ?>
                                        <?php foreach ($niveles as $nivel): ?>
                                            <option value="<?php echo htmlspecialchars($nivel); ?>"
                                                    <?php echo (($_POST['nivel_examen'] ?? '') === $nivel) ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($nivel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group-professional">
                                <label class="form-label-professional">
                                    <i class="fas fa-graduation-cap"></i>
                                    Programa
                                </label>
                                <select class="form-control-professional" name="programa">
                                    <option value="">Todos los programas</option>
                                    <?php if (isset($programas)): ?>
                                        <?php foreach ($programas as $programa): ?>
                                            <option value="<?php echo htmlspecialchars($programa); ?>"
                                                    <?php echo (($_POST['programa'] ?? '') === $programa) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($programa); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="actions-container">
                            <button type="submit" name="accion" value="vista_previa_sin_examen" class="btn-professional btn-info-professional">
                                <i class="fas fa-eye"></i>
                                Vista Previa Sin Examen
                            </button>
                            
                            <button type="submit" name="accion" value="generar_excel_sin_examen" class="btn-professional btn-danger-professional">
                                <i class="fas fa-download"></i>
                                Descargar Excel Sin Examen
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Estadísticas para estudiantes sin examen -->
        <?php if (isset($estadisticas_sin_examen)): ?>
            <div class="content-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_sin_examen['total_asignados']; ?></div>
                        <div class="stat-label">
                            <i class="fas fa-users"></i>
                            Total Asignados
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_sin_examen['sin_examen']; ?></div>
                        <div class="stat-label">
                            <i class="fas fa-user-times"></i>
                            Sin Examen
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_sin_examen['no_iniciados']; ?></div>
                        <div class="stat-label">
                            <i class="fas fa-play-circle"></i>
                            No Iniciados
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_sin_examen['iniciados_sin_terminar']; ?></div>
                        <div class="stat-label">
                            <i class="fas fa-pause-circle"></i>
                            Iniciados sin Terminar
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($estadisticas_sin_examen['porcentaje_pendientes'], 1); ?>%</div>
                        <div class="stat-label">
                            <i class="fas fa-percentage"></i>
                            % Pendientes
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($estadisticas_sin_examen['promedio_dias_asignacion'], 0); ?></div>
                        <div class="stat-label">
                            <i class="fas fa-calendar-day"></i>
                            Días Promedio
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tabla de vista previa para estudiantes sin examen -->
        <?php if (isset($datos_sin_examen) && !empty($datos_sin_examen)): ?>
            <div class="content-section">
                <div class="card-professional">
                    <div class="card-header-professional">
                        <h3 class="card-title">
                            <i class="fas fa-table"></i>
                            Estudiantes Sin Examen Realizado
                        </h3>
                    </div>
                    <div class="card-body-professional">
                        <div class="table-container">
                            <table class="table-professional">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-id-card"></i> Documento</th>
                                        <th><i class="fas fa-user"></i> Nombre</th>
                                        <th><i class="fas fa-envelope"></i> Correo</th>
                                        <th><i class="fas fa-graduation-cap"></i> Programa</th>
                                        <th><i class="fas fa-layer-group"></i> Nivel</th>
                                        <th><i class="fas fa-calendar-plus"></i> Fecha Asignación</th>
                                        <th><i class="fas fa-clock"></i> Días Transcurridos</th>
                                        <th><i class="fas fa-flag"></i> Estado</th>
                                        <th><i class="fas fa-edit"></i> Respuestas Parciales</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($datos_sin_examen as $estudiante): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($estudiante['identificacion']); ?></td>
                                            <td title="<?php echo htmlspecialchars($estudiante['nombre']); ?>">
                                                <?php echo htmlspecialchars(strlen($estudiante['nombre']) > 25 ? substr($estudiante['nombre'], 0, 25) . '...' : $estudiante['nombre']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($estudiante['correo']); ?></td>
                                            <td><?php echo htmlspecialchars($estudiante['programa'] ?? 'N/A'); ?></td>
                                            <td><?php echo $estudiante['nivel_dificultad'] ? ucfirst($estudiante['nivel_dificultad']) : 'Sin asignar'; ?></td>
                                            <td><?php echo $estudiante['fecha_asignacion'] ? date('d/m/Y', strtotime($estudiante['fecha_asignacion'])) : 'N/A'; ?></td>
                                            <td>
                                                <?php if ($estudiante['dias_desde_asignacion'] !== null): ?>
                                                    <span class="badge-professional <?php echo $estudiante['dias_desde_asignacion'] > 7 ? 'badge-danger' : ($estudiante['dias_desde_asignacion'] > 3 ? 'badge-warning' : 'badge-success'); ?>">
                                                        <?php echo $estudiante['dias_desde_asignacion']; ?> días
                                                    </span>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $estado_class = '';
                                                switch($estudiante['estado_examen']) {
                                                    case 'NO INICIADO':
                                                        $estado_class = 'badge-warning';
                                                        break;
                                                    case 'INICIADO SIN TERMINAR':
                                                        $estado_class = 'badge-danger';
                                                        break;
                                                    case 'SIN ASIGNAR':
                                                        $estado_class = 'badge-danger';
                                                        break;
                                                    default:
                                                        $estado_class = 'badge-success';
                                                }
                                                ?>
                                                <span class="badge-professional <?php echo $estado_class; ?>">
                                                    <?php echo htmlspecialchars($estudiante['estado_examen']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge-professional <?php echo $estudiante['respuestas_parciales'] > 0 ? 'badge-warning' : 'badge-success'; ?>">
                                                    <?php echo $estudiante['respuestas_parciales']; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- ✅ AGREGAR SWEETALERT2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Animación suave al cargar
        document.addEventListener('DOMContentLoaded', function() {
            const sections = document.querySelectorAll('.content-section');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    section.style.transition = 'all 0.6s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // ✅ SWEETALERT PROFESIONAL PARA DESCARGA DE EXCEL
        document.getElementById('formInforme').addEventListener('submit', function(e) {
            if (e.submitter && e.submitter.value === 'generar_excel') {
                e.preventDefault(); // Detener el envío inicial
                
                Swal.fire({
                    title: '📊 Generar Informe Excel',
                    html: `
                        <div style="text-align: center; padding: 20px;">
                            <div style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); 
                                        border-radius: 12px; padding: 24px; margin-bottom: 20px;">
                                <i class="fas fa-file-excel" style="font-size: 3rem; color: #2196F3; margin-bottom: 16px;"></i>
                                <h3 style="color: #1976d2; margin-bottom: 12px; font-weight: 600;">
                                    Informe Detallado por Competencias
                                </h3>
                                <p style="color: #1565c0; margin-bottom: 8px; font-size: 1.1rem;">
                                    <strong>Formato:</strong> Excel (.xlsx)
                                </p>
                                <p style="color: #1565c0; margin-bottom: 8px;">
                                    <strong>Contenido:</strong> Datos completos con competencias y resultados
                                </p>
                                <p style="color: #1565c0; margin-bottom: 0; font-size: 0.95rem;">
                                    <strong>Nota:</strong> Las competencias reprobadas aparecerán resaltadas en rojo
                                </p>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: center; gap: 12px; 
                                        background: #f8f9fa; padding: 16px; border-radius: 8px; margin-top: 16px;">
                                <i class="fas fa-info-circle" style="color: #17a2b8; font-size: 1.2rem;"></i>
                                <span style="color: #495057; font-size: 0.95rem;">
                                    El archivo se descargará automáticamente
                                </span>
                            </div>
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-download"></i> Generar y Descargar',
                    cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    width: '550px',
                    padding: '2rem',
                    backdrop: 'rgba(0,0,0,0.4)',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    customClass: {
                        popup: 'swal-modern-popup',
                        title: 'swal-modern-title',
                        confirmButton: 'swal-modern-confirm-btn',
                        cancelButton: 'swal-modern-cancel-btn'
                    },
                    didOpen: () => {
                        // Añadir estilos personalizados
                        const popup = Swal.getPopup();
                        popup.style.borderRadius = '16px';
                        popup.style.border = '2px solid #2196F3';
                        popup.style.boxShadow = '0 20px 40px rgba(33, 150, 243, 0.2)';
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Mostrar loading mientras se genera el archivo
                        const loadingAlert = Swal.fire({
                            title: '📊 Generando Informe Excel',
                            html: `
                                <div style="text-align: center; padding: 30px;">
                                    <div style="position: relative; display: inline-block; margin-bottom: 20px;">
                                        <i class="fas fa-file-excel" style="font-size: 4rem; color: #2196F3; 
                                                                             animation: pulse 2s infinite;"></i>
                                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
                                                    width: 80px; height: 80px; border: 3px solid #e3f2fd; 
                                                    border-top: 3px solid #2196F3; border-radius: 50%;
                                                    animation: spin 1s linear infinite;"></div>
                                    </div>
                                    <h3 style="color: #1976d2; margin-bottom: 12px; font-weight: 600;">
                                        Procesando datos...
                                    </h3>
                                    <p style="color: #666; margin-bottom: 16px;">
                                        Por favor espere mientras se genera el informe
                                    </p>
                                    <div style="background: #f8f9fa; padding: 12px; border-radius: 8px;">
                                        <small style="color: #666;">
                                            <i class="fas fa-clock"></i> Tiempo estimado: 10-30 segundos
                                        </small>
                                    </div>
                                </div>
                                <style>
                                    @keyframes pulse {
                                        0%, 100% { transform: scale(1); }
                                        50% { transform: scale(1.1); }
                                    }
                                    @keyframes spin {
                                        0% { transform: translate(-50%, -50%) rotate(0deg); }
                                        100% { transform: translate(-50%, -50%) rotate(360deg); }
                                    }
                                </style>
                            `,
                            showConfirmButton: false,
                            showCancelButton: false,
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            width: '450px',
                            padding: '2rem',
                            backdrop: 'rgba(0,0,0,0.8)',
                            customClass: {
                                popup: 'swal-loading-popup'
                            }
                        });
                        
                        // Enviar el formulario
                        const form = document.getElementById('formInforme');
                        const formData = new FormData(form);
                        formData.set('accion', 'generar_excel');
                        
                        // Crear un formulario temporal y enviarlo
                        const tempForm = document.createElement('form');
                        tempForm.method = 'POST';
                        tempForm.style.display = 'none';
                        
                        for (let [key, value] of formData.entries()) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = key;
                            input.value = value;
                            tempForm.appendChild(input);
                        }
                        
                        document.body.appendChild(tempForm);
                        tempForm.submit();
                        
                        // Cerrar el loading después de un tiempo
                        setTimeout(() => {
                            loadingAlert.close();
                            
                            // Mostrar mensaje de éxito
                            Swal.fire({
                                title: '✅ ¡Descarga Iniciada!',
                                html: `
                                    <div style="text-align: center; padding: 20px;">
                                        <i class="fas fa-check-circle" style="font-size: 3rem; color: #28a745; margin-bottom: 16px;"></i>
                                        <h3 style="color: #155724; margin-bottom: 12px;">
                                            Informe generado exitosamente
                                        </h3>
                                        <p style="color: #666; margin-bottom: 16px;">
                                            El archivo Excel se está descargando automáticamente
                                        </p>
                                        <div style="background: #d4edda; border: 1px solid #c3e6cb; 
                                                    padding: 12px; border-radius: 8px; margin-top: 16px;">
                                            <small style="color: #155724;">
                                                <i class="fas fa-info-circle"></i> 
                                                Si la descarga no inicia, revise la carpeta de descargas
                                            </small>
                                        </div>
                                    </div>
                                `,
                                icon: 'success',
                                confirmButtonText: '<i class="fas fa-check"></i> Entendido',
                                confirmButtonColor: '#28a745',
                                timer: 5000,
                                timerProgressBar: true,
                                customClass: {
                                    popup: 'swal-success-popup'
                                }
                            });
                        }, 2000);
                    }
                });
            }
        });

        // Agrega este código después del JavaScript existente
        document.getElementById('formInformeSinExamen').addEventListener('submit', function(e) {
            if (e.submitter && e.submitter.value === 'generar_excel_sin_examen') {
                e.preventDefault();
                
                Swal.fire({
                    title: '👥 Informe de Estudiantes Sin Examen',
                    html: `
                        <div style="text-align: center; padding: 20px;">
                            <div style="background: linear-gradient(135deg, #fff3e0, #ffcc80); 
                                        border-radius: 12px; padding: 24px; margin-bottom: 20px;">
                                <i class="fas fa-user-clock" style="font-size: 3rem; color: #f57c00; margin-bottom: 16px;"></i>
                                <h3 style="color: #e65100; margin-bottom: 12px; font-weight: 600;">
                                    Estudiantes Pendientes de Examen
                                </h3>
                                <p style="color: #ef6c00; margin-bottom: 8px; font-size: 1.1rem;">
                                    <strong>Incluye:</strong> Estudiantes con examen asignado pero no realizado
                                </p>
                                <p style="color: #ef6c00; margin-bottom: 8px;">
                                    <strong>Estados:</strong> Sin iniciar, Iniciado sin terminar, Sin asignar
                                </p>
                                <p style="color: #ef6c00; margin-bottom: 0; font-size: 0.95rem;">
                                    <strong>Ideal para:</strong> Seguimiento y recordatorios
                                </p>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: center; gap: 12px; 
                                        background: #f8f9fa; padding: 16px; border-radius: 8px; margin-top: 16px;">
                                <i class="fas fa-info-circle" style="color: #17a2b8; font-size: 1.2rem;"></i>
                                <span style="color: #495057; font-size: 0.95rem;">
                                    Se incluyen campos adicionales como días transcurridos
                                </span>
                            </div>
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-download"></i> Generar Excel',
                    cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    width: '550px'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Mostrar loading
                        Swal.fire({
                            title: '👥 Generando Informe...',
                            html: `
                                <div style="text-align: center; padding: 30px;">
                                    <i class="fas fa-user-clock" style="font-size: 4rem; color: #f57c00; animation: pulse 2s infinite;"></i>
                                    <h3 style="color: #e65100; margin: 20px 0;">Procesando estudiantes pendientes...</h3>
                                </div>
                            `,
                            showConfirmButton: false,
                            allowOutsideClick: false
                        });
                        
                        // Enviar formulario
                        const form = document.getElementById('formInformeSinExamen');
                        const formData = new FormData(form);
                        formData.set('accion', 'generar_excel_sin_examen');
                        
                        const tempForm = document.createElement('form');
                        tempForm.method = 'POST';
                        tempForm.style.display = 'none';
                        
                        for (let [key, value] of formData.entries()) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = key;
                            input.value = value;
                            tempForm.appendChild(input);
                        }
                        
                        document.body.appendChild(tempForm);
                        tempForm.submit();
                        
                        setTimeout(() => {
                            Swal.close();
                            Swal.fire({
                                title: '✅ ¡Descarga Iniciada!',
                                text: 'El informe de estudiantes sin examen se está descargando',
                                icon: 'success',
                                confirmButtonColor: '#28a745'
                            });
                        }, 2000);
                    }
                });
            }
        });
    </script>
    
    <!-- ✅ ESTILOS PERSONALIZADOS PARA SWEETALERT -->
    <style>
        .swal-modern-popup {
            border-radius: 16px !important;
            border: 2px solid #2196F3 !important;
            box-shadow: 0 20px 40px rgba(33, 150, 243, 0.2) !important;
        }
        
        .swal-modern-title {
            color: #1976d2 !important;
            font-weight: 600 !important;
            font-size: 1.5rem !important;
        }
        
        .swal-modern-confirm-btn {
            border-radius: 8px !important;
            padding: 12px 24px !important;
            font-weight: 600 !important;
            font-size: 1rem !important;
            border: none !important;
            transition: all 0.3s ease !important;
        }
        
        .swal-modern-confirm-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 16px rgba(40, 167, 69, 0.3) !important;
        }
        
        .swal-modern-cancel-btn {
            border-radius: 8px !important;
            padding: 12px 24px !important;
            font-weight: 600 !important;
            font-size: 1rem !important;
            border: none !important;
            transition: all 0.3s ease !important;
        }
        
        .swal-modern-cancel-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 16px rgba(108, 117, 125, 0.3) !important;
        }
        
        .swal-loading-popup {
            border-radius: 16px !important;
            border: 2px solid #2196F3 !important;
        }
        
        .swal-success-popup {
            border-radius: 16px !important;
            border: 2px solid #28a745 !important;
        }
    </style>
</body>
     <?php require_once '../includes/footer.php'; ?>
</html>
