<?php
session_start();
require_once 'config/db.php';

// **MOVER LA VERIFICACIÓN DE SESIÓN AL INICIO**
if (!isset($_SESSION['participante_id'])) {
    header('Location: index.php');
    exit;
}

$participante_id = $_SESSION['participante_id'];
$participante_nombre = $_SESSION['participante_nombre'];

// **AHORA SÍ HACER DEBUG**
error_log("=== DEBUG VER_RESULTADO ===");
error_log("Participante ID: $participante_id");

// Verificar respuestas en BD
$stmt_debug = $pdo->prepare("SELECT COUNT(*) as total FROM respuestas WHERE id_participante = ?");
$stmt_debug->execute([$participante_id]);
$debug_respuestas = $stmt_debug->fetchColumn();
error_log("Total respuestas en BD: $debug_respuestas");

// Verificar resultados en BD
$stmt_debug2 = $pdo->prepare("SELECT COUNT(*) as total FROM resultados WHERE participante_id = ?");
$stmt_debug2->execute([$participante_id]);
$debug_resultados = $stmt_debug2->fetchColumn();
error_log("Total resultados en BD: $debug_resultados");

// Verificar estado del participante
$stmt_debug3 = $pdo->prepare("SELECT estado_examen FROM participantes WHERE id_participante = ?");
$stmt_debug3->execute([$participante_id]);
$debug_estado = $stmt_debug3->fetchColumn();
error_log("Estado participante: $debug_estado");

// **VARIABLES INICIALES**
$resultados = [];
$participante_info = null;
$total_preguntas = 0;
$total_correctas = 0;
$porcentaje_general = 0;
$estado_final = 'SIN RESULTADOS';
$fecha_examen = null; // **IMPORTANTE: Inicializar esta variable**
$sin_resultados = true; // **IMPORTANTE: Inicializar por defecto**

// Manejar mensajes específicos
$mensaje_especial = '';
if (isset($_GET['mensaje'])) {
    switch ($_GET['mensaje']) {
        case 'ya_aprobado':
            $mensaje_especial = [
                'tipo' => 'success',
                'titulo' => '¡Felicitaciones!',
                'mensaje' => 'Ya has aprobado el examen. No puedes realizarlo nuevamente.'
            ];
            break;
        case 'reprobado_sin_rehabilitar':
            $mensaje_especial = [
                'tipo' => 'warning',
                'titulo' => 'Examen No Aprobado',
                'mensaje' => 'No alcanzaste el puntaje mínimo requerido. Contacta al administrador para una rehabilitación si deseas intentar nuevamente.'
            ];
            break;
        case 'examen_completado':
            $mensaje_especial = [
                'tipo' => 'info',
                'titulo' => 'Examen Completado',
                'mensaje' => 'Has completado el examen exitosamente. Aquí están tus resultados.'
            ];
            break;
        case 'sin_rehabilitaciones':
            $mensaje_especial = [
                'tipo' => 'warning',
                'titulo' => 'Sin Rehabilitaciones Disponibles',
                'mensaje' => 'No tienes rehabilitaciones activas disponibles en este momento.'
            ];
            break;
        case 'rehabilitacion_completada':
            $mensaje_especial = [
                'tipo' => 'success',
                'titulo' => '¡Rehabilitación Completada!',
                'mensaje' => 'Has completado exitosamente tu examen de rehabilitación. Tu resultado ha sido recalculado automáticamente.'
            ];
            break;
    }
}

try {
    // Obtener información del participante
    $stmt_participante = $pdo->prepare("
        SELECT p.*, ae.nivel_dificultad as nivel_examen, ae.fecha_asignacion
        FROM participantes p
        LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
        WHERE p.id_participante = ?
    ");
    $stmt_participante->execute([$participante_id]);
    $participante_info = $stmt_participante->fetch(PDO::FETCH_ASSOC);
    
    if (!$participante_info) {
        throw new Exception("Participante no encontrado en la base de datos");
    }
    
    // **VERIFICACIÓN MEJORADA:**
    // 1. Verificar si tiene respuestas
    $stmt_check_respuestas = $pdo->prepare("SELECT COUNT(*) FROM respuestas WHERE id_participante = ?");
    $stmt_check_respuestas->execute([$participante_id]);
    $count_respuestas = $stmt_check_respuestas->fetchColumn();
    
    // 2. Verificar si tiene resultados calculados
    $stmt_check_resultados = $pdo->prepare("SELECT COUNT(*) FROM resultados WHERE participante_id = ?");
    $stmt_check_resultados->execute([$participante_id]);
    $count_resultados = $stmt_check_resultados->fetchColumn();
    
    error_log("VERIFICACIÓN - Respuestas: $count_respuestas, Resultados: $count_resultados");
    
    // **DETERMINAR SI TIENE RESULTADOS**
    $tiene_respuestas = $count_respuestas > 0;
    $tiene_resultados_calculados = $count_resultados > 0;
    
    // **LÓGICA CORREGIDA:**
    if (!$tiene_respuestas && !$tiene_resultados_calculados) {
        // NO ha hecho el examen
        $sin_resultados = true;
        error_log("ESTADO: Sin resultados - No tiene respuestas ni resultados");
    } else {
        // SÍ ha hecho el examen
        $sin_resultados = false;
        error_log("ESTADO: Con resultados - Respuestas: $count_respuestas, Resultados: $count_resultados");
        
        // **PROCESAR RESULTADOS USANDO COMPETENCIAS CONSOLIDADAS**
        if ($tiene_resultados_calculados || $tiene_respuestas) {
            error_log("PROCESANDO RESULTADOS - Respuestas: $count_respuestas, Resultados: $count_resultados");
            
            // **USAR COMPETENCIAS CONSOLIDADAS SIEMPRE**
            $resultados = obtenerCompetenciasConsolidadas($participante_id, $pdo);
            
            if (!empty($resultados)) {
                // Calcular totales consolidados
                $total_preguntas_consolidado = 0;
                $total_correctas_consolidado = 0;
                
                foreach ($resultados as $comp) {
                    $total_preguntas_consolidado += $comp['total_preguntas'];
                    $total_correctas_consolidado += $comp['correctas'];
                }
                
                if ($total_preguntas_consolidado > 0) {
                    $total_preguntas = $total_preguntas_consolidado;
                    $total_correctas = $total_correctas_consolidado;
                    $porcentaje_general = ($total_correctas / $total_preguntas) * 100;
                    
                    // Obtener fecha del último examen
                    $stmt_fecha_ultimo = $pdo->prepare("
                        SELECT fecha_realizacion 
                        FROM historial_examenes 
                        WHERE participante_id = ? 
                        ORDER BY intento_numero DESC, fecha_realizacion DESC 
                        LIMIT 1
                    ");
                    $stmt_fecha_ultimo->execute([$participante_id]);
                    $fecha_examen = $stmt_fecha_ultimo->fetchColumn();
                    
                    if (!$fecha_examen) {
                        $fecha_examen = date('Y-m-d H:i:s');
                    }
                    
                    error_log("COMPETENCIAS CONSOLIDADAS - Total: $total_preguntas, Correctas: $total_correctas, %: $porcentaje_general");
                    
                    $sin_resultados = false;
                } else {
                    throw new Exception("Error en cálculo de totales consolidados");
                }
            } else {
                throw new Exception("No se pudieron obtener competencias consolidadas");
            }
        }

        // **DETERMINAR ESTADO FINAL**
        $todas_competencias_aprobadas = true;
        if (!empty($resultados)) {
            foreach ($resultados as $resultado) {
                if ($resultado['porcentaje'] < 70) {
                    $todas_competencias_aprobadas = false;
                    break;
                }
            }
        }
        
        // Determinar estado basado en competencias Y porcentaje general
        if (!$todas_competencias_aprobadas || $porcentaje_general < 70) {
            $estado_final = 'NO APROBADO';
        } elseif ($porcentaje_general >= 85) {
            $estado_final = 'EXCELENTE';
        } else {
            $estado_final = 'APROBADO';
        }
        
        error_log("ESTADO FINAL DETERMINADO: $estado_final");
        
        // **REDIRECCIÓN AUTOMÁTICA PARA REHABILITACIONES - SOLO SI NO VIENEN PARÁMETROS ESPECIALES**
        if ($estado_final === 'NO APROBADO' && 
            !isset($_GET['no_redirect']) && 
            !isset($_GET['mensaje']) && 
            !isset($_GET['manual']) &&
            !isset($_GET['from_exam']) &&
            !isset($_GET['from_index']) &&
            !isset($_GET['from_rehab']) &&
            !isset($_GET['debug'])) {
            
            // Verificar rehabilitaciones disponibles
            $stmt_rehab = $pdo->prepare("
                SELECT COUNT(*) as rehabilitaciones_activas 
                FROM rehabilitaciones_competencia 
                WHERE participante_id = ? AND estado = 'ACTIVA'
            ");
            $stmt_rehab->execute([$participante_id]);
            $tiene_rehabilitaciones = $stmt_rehab->fetchColumn() > 0;
            
            if ($tiene_rehabilitaciones && $participante_info['estado_examen'] !== 'REHABILITADO') {
                // Actualizar estado
                $stmt_update = $pdo->prepare("UPDATE participantes SET estado_examen = 'REHABILITADO' WHERE id_participante = ?");
                $stmt_update->execute([$participante_id]);
                
                error_log("REDIRECCIÓN AUTOMÁTICA - Participante $participante_id redirigido a rehabilitación");
                
                header('Location: examen.php?auto_rehab=1&from_result=1');
                exit;
            }
        }
    }
    
} catch (Exception $e) {
    error_log("ERROR en ver_resultado.php: " . $e->getMessage());
    $error_mensaje = $e->getMessage();
    
    // **EN CASO DE ERROR, VERIFICAR DIRECTAMENTE SI TIENE DATOS**
    try {
        $stmt_emergency = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM respuestas WHERE id_participante = ?) as respuestas,
                (SELECT COUNT(*) FROM resultados WHERE participante_id = ?) as resultados
        ");
        $stmt_emergency->execute([$participante_id, $participante_id]);
        $emergency_check = $stmt_emergency->fetch(PDO::FETCH_ASSOC);
        
        if ($emergency_check['respuestas'] > 0 || $emergency_check['resultados'] > 0) {
            error_log("EMERGENCIA - Forzando sin_resultados = false por datos existentes");
            $sin_resultados = false;
            
            // Datos básicos de emergencia
            $total_preguntas = $emergency_check['respuestas'] ?: 1;
            $total_correctas = 0;
            $porcentaje_general = 0;
            $resultados = [];
            $estado_final = 'ERROR EN CÁLCULO';
            $fecha_examen = date('Y-m-d H:i:s'); // **IMPORTANTE: Asignar fecha por defecto**
        } else {
            $sin_resultados = true;
        }
    } catch (Exception $e2) {
        error_log("ERROR CRÍTICO en verificación de emergencia: " . $e2->getMessage());
        $sin_resultados = true;
    }
}

// **VERIFICAR QUE TODAS LAS VARIABLES ESTÉN DEFINIDAS ANTES DE CONTINUAR**
if (!isset($fecha_examen) || !$fecha_examen) {
    $fecha_examen = date('Y-m-d H:i:s'); // Fallback por defecto
}

if (!isset($participante_info) || !$participante_info) {
    $participante_info = [
        'nombre' => $participante_nombre ?? 'Usuario Desconocido',
        'nivel_examen' => 'Sin asignar',
        'fecha_asignacion' => date('Y-m-d')
    ];
}

// **AGREGAR ESTAS FUNCIONES FALTANTES:**

/**
 * Generar colores dinámicos para competencias
 */
function generateCompetenciaColor($nombre, $index) {
    // Paleta de colores profesionales
    $colores = [
        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
        '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF',
        '#4BC0C0', '#36A2EB', '#FFCE56', '#9966FF'
    ];
    
    // Usar hash del nombre para consistencia
    $hash = crc32($nombre);
    $colorIndex = abs($hash) % count($colores);
    
    return $colores[$colorIndex];
}

/**
 * Generar fondo degradado para competencias
 */
function generateBackgroundGradient($color) {
    // Convertir hex a RGB para hacer variaciones
    $hex = str_replace('#', '', $color);
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    // Crear versión más clara (15% más claro)
    $r_light = min(255, $r + 40);
    $g_light = min(255, $g + 40);
    $b_light = min(255, $b + 40);
    
    $color_light = sprintf("#%02x%02x%02x", $r_light, $g_light, $b_light);
    
    return "linear-gradient(135deg, {$color}15, {$color_light}25)";
}

/**
 * Función para obtener competencias consolidadas considerando rehabilitaciones
 */
function obtenerCompetenciasConsolidadas($participante_id, $pdo) {
    $competencias_finales = [];
    
    try {
        error_log("=== OBTENIENDO COMPETENCIAS CONSOLIDADAS ===");
        
        // 1. Obtener todas las competencias que ha evaluado (inicial + rehabilitaciones)
        $stmt_todas_comp = $pdo->prepare("
            SELECT DISTINCT 
                hc.competencia_id,
                hc.competencia_nombre
            FROM historial_competencias hc
            JOIN historial_examenes he ON hc.historial_id = he.id_historial
            WHERE he.participante_id = ?
            ORDER BY hc.competencia_nombre
        ");
        $stmt_todas_comp->execute([$participante_id]);
        $todas_competencias = $stmt_todas_comp->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Competencias encontradas en historial: " . count($todas_competencias));
        
        if (empty($todas_competencias)) {
            // Si no hay historial_competencias, construir desde respuestas
            error_log("No hay historial_competencias, construyendo desde respuestas...");
            return construirDesdeRespuestas($participante_id, $pdo);
        }
        
        // 2. Para cada competencia, obtener el resultado más reciente
        foreach ($todas_competencias as $comp_base) {
            $stmt_ultimo = $pdo->prepare("
                SELECT hc.*, he.intento_numero, he.fecha_realizacion
                FROM historial_competencias hc
                JOIN historial_examenes he ON hc.historial_id = he.id_historial
                WHERE he.participante_id = ? 
                AND hc.competencia_id = ?
                ORDER BY he.intento_numero DESC, he.fecha_realizacion DESC
                LIMIT 1
            ");
            $stmt_ultimo->execute([$participante_id, $comp_base['competencia_id']]);
            $ultimo_resultado = $stmt_ultimo->fetch(PDO::FETCH_ASSOC);
            
            if ($ultimo_resultado) {
                $competencias_finales[] = [
                    'competencia_id' => $comp_base['competencia_id'],
                    'competencia' => $comp_base['competencia_nombre'],
                    'total_preguntas' => $ultimo_resultado['total_preguntas'],
                    'correctas' => $ultimo_resultado['respuestas_correctas'],
                    'respuestas_correctas' => $ultimo_resultado['respuestas_correctas'], // Alias
                    'porcentaje' => floatval($ultimo_resultado['porcentaje']),
                    'aprobado' => $ultimo_resultado['porcentaje'] >= 70,
                    'ultimo_intento' => $ultimo_resultado['intento_numero'],
                    'fecha_ultimo' => $ultimo_resultado['fecha_realizacion']
                ];
                
                error_log("Competencia consolidada: {$comp_base['competencia_nombre']} - {$ultimo_resultado['porcentaje']}% (Intento #{$ultimo_resultado['intento_numero']})");
            }
        }
        
        error_log("Total competencias consolidadas: " . count($competencias_finales));
        return $competencias_finales;
        
    } catch (Exception $e) {
        error_log("Error en obtenerCompetenciasConsolidadas: " . $e->getMessage());
        return construirDesdeRespuestas($participante_id, $pdo);
    }
}

/**
 * Función auxiliar para construir competencias desde respuestas cuando no hay historial
 */
function construirDesdeRespuestas($participante_id, $pdo) {
    error_log("Construyendo competencias desde respuestas directas...");
    
    try {
        $stmt_respuestas = $pdo->prepare("
            SELECT 
                p.id_competencia,
                c.nombre as competencia,
                COUNT(*) as total_preguntas,
                SUM(CASE WHEN o.es_correcta THEN 1 ELSE 0 END) as correctas,
                ROUND((SUM(CASE WHEN o.es_correcta THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 1) as porcentaje
            FROM respuestas r
            JOIN preguntas p ON r.id_pregunta = p.id_pregunta
            JOIN competencias c ON p.id_competencia = c.id_competencia
            JOIN opciones o ON r.id_opcion = o.id_opcion
            WHERE r.id_participante = ?
            GROUP BY p.id_competencia, c.nombre
            ORDER BY c.nombre
        ");
        $stmt_respuestas->execute([$participante_id]);
        $competencias_respuestas = $stmt_respuestas->fetchAll(PDO::FETCH_ASSOC);
        
        $competencias_resultado = [];
        foreach ($competencias_respuestas as $comp) {
            $competencias_resultado[] = [
                'competencia_id' => $comp['id_competencia'],
                'competencia' => $comp['competencia'],
                'total_preguntas' => intval($comp['total_preguntas']),
                'correctas' => intval($comp['correctas']),
                'respuestas_correctas' => intval($comp['correctas']),
                'porcentaje' => floatval($comp['porcentaje']),
                'aprobado' => $comp['porcentaje'] >= 70,
                'ultimo_intento' => 1,
                'fecha_ultimo' => date('Y-m-d H:i:s')
            ];
            
            error_log("Competencia desde respuestas: {$comp['competencia']} - {$comp['porcentaje']}%");
        }
        
        return $competencias_resultado;
        
    } catch (Exception $e) {
        error_log("Error en construirDesdeRespuestas: " . $e->getMessage());
        return [];
    }
}

// **MANEJO SEGURO DE COMPETENCIAS DINÁMICAS**
$competencias_dinamicas = [];
if (!empty($resultados)) {
    foreach ($resultados as $index => $resultado) {
        if (isset($resultado['competencia']) && !empty($resultado['competencia'])) {
            $competencias_dinamicas[$resultado['competencia']] = [
                'color' => generateCompetenciaColor($resultado['competencia'], $index),
                'index' => $index
            ];
        }
    }
}

error_log("COMPETENCIAS DINÁMICAS GENERADAS: " . count($competencias_dinamicas));

// **DEBUG FINAL PARA VERIFICAR VARIABLES**
error_log("=== VARIABLES FINALES ===");
error_log("sin_resultados: " . ($sin_resultados ? 'true' : 'false'));
error_log("total_preguntas: $total_preguntas");
error_log("total_correctas: $total_correctas");
error_log("porcentaje_general: $porcentaje_general");
error_log("estado_final: $estado_final");
error_log("fecha_examen: $fecha_examen");
error_log("resultados count: " . count($resultados));

// **VALIDACIÓN Y SANITIZACIÓN DE VARIABLES**
$porcentaje_general = is_numeric($porcentaje_general) ? floatval($porcentaje_general) : 0;
$total_preguntas = is_numeric($total_preguntas) ? intval($total_preguntas) : 0;
$total_correctas = is_numeric($total_correctas) ? intval($total_correctas) : 0;

// Asegurar que $resultados es un array válido
if (!is_array($resultados)) {
    $resultados = [];
}

// Validar cada resultado
foreach ($resultados as $key => &$resultado) {
    if (!isset($resultado['competencia'])) {
        $resultado['competencia'] = 'Competencia Desconocida';
    }
    if (!isset($resultado['porcentaje'])) {
        $resultado['porcentaje'] = 0;
    }
    if (!isset($resultado['total_preguntas'])) {
        $resultado['total_preguntas'] = 0;
    }
    if (!isset($resultado['correctas'])) {
        $resultado['correctas'] = 0;
    }
    if (!isset($resultado['aprobado'])) {
        $resultado['aprobado'] = $resultado['porcentaje'] >= 70;
    }
    
    // Asegurar que los valores numéricos sean válidos
    $resultado['porcentaje'] = is_numeric($resultado['porcentaje']) ? floatval($resultado['porcentaje']) : 0;
    $resultado['total_preguntas'] = is_numeric($resultado['total_preguntas']) ? intval($resultado['total_preguntas']) : 0;
    $resultado['correctas'] = is_numeric($resultado['correctas']) ? intval($resultado['correctas']) : 0;
}
unset($resultado); // Limpiar referencia

// Asegurar que el estado final es válido
$estados_validos = ['EXCELENTE', 'APROBADO', 'REGULAR', 'NO APROBADO', 'ERROR EN CÁLCULO'];
if (!in_array($estado_final, $estados_validos)) {
    $estado_final = 'ERROR EN CÁLCULO';
}

// **DEBUG FINAL LIMPIEZA**
error_log("=== VARIABLES SANITIZADAS ===");
error_log("porcentaje_general (sanitizado): $porcentaje_general");
error_log("total_preguntas (sanitizado): $total_preguntas");
error_log("total_correctas (sanitizado): $total_correctas");
error_log("resultados count (sanitizado): " . count($resultados));
error_log("estado_final (sanitizado): $estado_final");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados del Examen - INCATEC</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <!-- Favicon INCATEC -->
    <link rel="icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link rel="apple-touch-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --azul-incatec: #003f91;
            --rojo-incatec: #d72638;
            --verde-success: #28a745;
            --naranja-warning: #ff9800;
            --blanco: #ffffff;
            --gris-suave: #f5f7fa;
            --gris-medio: #e0e6ed;
            --gris-oscuro: #2d3748;
            --sombra-suave: 0 2px 8px rgba(0,0,0,0.1);
            --transicion: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif;
            background: var(--gris-suave);
            line-height: 1.6;
            color: var(--gris-oscuro);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(90deg, var(--azul-incatec) 60%, #2356ad 100%);
            color: var(--blanco);
            padding: 20px 0;
            box-shadow: var(--sombra-suave);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.9rem;
        }

        .btn-logout {
            background: var(--rojo-incatec);
            color: var(--blanco);
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transicion);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-logout:hover {
            background: #c21e2f;
            transform: translateY(-1px);
        }

        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
            flex: 1;
        }

        .result-header {
            background: var(--blanco);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--sombra-suave);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .result-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--verde-success) 0%, var(--azul-incatec) 100%);
        }

        .status-badge {
            display: inline-block;
            padding: 10px 24px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-excelente {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: var(--blanco);
            box-shadow: 0 4px 15px rgba(40,167,69,0.3);
        }

        .status-aprobado {
            background: linear-gradient(135deg, #17a2b8, #28a745);
            color: var(--blanco);
            box-shadow: 0 4px 15px rgba(23,162,184,0.3);
        }

        .status-regular {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            color: var(--gris-oscuro);
            box-shadow: 0 4px 15px rgba(255,193,7,0.3);
        }

        .status-no-aprobado {
            background: linear-gradient(135deg, #dc3545, #e83e8c);
            color: var(--blanco);
            box-shadow: 0 4px 15px rgba(220,53,69,0.3);
        }

        .score-display {
            font-size: 3rem;
            font-weight: 800;
            color: var(--azul-incatec);
            margin: 20px 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .participant-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 24px;
            text-align: left;
        }

        .info-card {
            background: var(--gris-suave);
            padding: 16px;
            border-radius: 8px;
            border-left: 4px solid var(--azul-incatec);
        }

        .info-card label {
            font-weight: 600;
            color: var(--gris-oscuro);
            font-size: 0.85rem;
            display: block;
            margin-bottom: 4px;
        }

        .info-card span {
            color: var(--azul-incatec);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .results-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .competencias-section {
            background: var(--blanco);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--sombra-suave);
        }

        .section-title {
            color: var(--azul-incatec);
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .competencia-item {
            background: var(--gris-suave);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            transition: var(--transicion);
        }

        .competencia-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--sombra-suave);
        }

        .competencia-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .competencia-name {
            font-weight: 600;
            color: var(--gris-oscuro);
        }

        .competencia-score {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .score-excelente { color: #28a745; }
        .score-aprobado { color: #17a2b8; }
        .score-regular { color: #ffc107; }
        .score-no-aprobado { color: #dc3545; }

        .progress-bar {
            background: var(--gris-medio);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.8s ease;
            position: relative;
        }

        .progress-excelente { background: linear-gradient(90deg, #28a745, #20c997); }
        .progress-aprobado { background: linear-gradient(90deg, #17a2b8, #28a745); }
        .progress-regular { background: linear-gradient(90deg, #ffc107, #fd7e14); }
        .progress-no-aprobado { background: linear-gradient(90deg, #dc3545, #e83e8c); }

        .color-legend {
            background: var(--blanco);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: var(--sombra-suave);
        }

        .legend-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gris-oscuro);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-items {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 12px;
            background: var(--gris-suave);
            font-size: 0.8rem;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }

        .chart-container {
            background: var(--blanco);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--sombra-suave);
            position: relative;
            min-height: 400px;
        }
        
        .chart-container canvas {
            max-height: 350px !important;
            width: 100% !important;
            height: auto !important;
        }
        
        .chart-loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-results {
            background: var(--blanco);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            box-shadow: var(--sombra-suave);
        }

        .no-results i {
            font-size: 4rem;
            color: var(--naranja-warning);
            margin-bottom: 20px;
        }

        .no-results h2 {
            color: var(--gris-oscuro);
            margin-bottom: 16px;
        }

        .btn-take-exam {
            background: var(--azul-incatec);
            color: var(--blanco);
            margin-top: 4px;
        }

        .summary-stats {
            background: var(--blanco);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: var(--sombra-suave);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 20px;
            background: var(--gris-suave);
            border-radius: 8px;
            transition: var(--transicion);
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--sombra-suave);
        }

        .stat-number {
            display: block;
            font-size: 2rem;
            font-weight: 700;
            color: var(--azul-incatec);
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gris-oscuro);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .container {
                padding: 0 15px;
                margin: 20px auto;
            }
            
            .results-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .participant-info {
                grid-template-columns: 1fr;
            }
            
            .score-display {
                font-size: 2.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .legend-items {
                gap: 8px;
            }

            .legend-item {
                font-size: 0.75rem;
                padding: 3px 6px;
            }

            .legend-color {
                width: 10px;
                height: 10px;
            }
        }

        /* Estilos para alertas especiales */
        .alert {
            padding: 20px 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 5px solid;
        }

        .alert-success {
            background: linear-gradient(135deg, #e8f5e9, #f1f8e9);
            border-left-color: #4caf50;
            color: #2e7d32;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fff3e0, #fef7e0);
            border-left-color: #ff9800;
            color: #ef6c00;
        }

        .alert-info {
            background: linear-gradient(135deg, #e3f2fd, #e8f4fd);
            border-left-color: #2196f3;
            color: #1565c0;
        }
        
        /* Estilos para el footer */
        footer {
            margin-top: auto;
            background: var(--gris-oscuro);
            padding: 15px 0;
            text-align: center;
            border-top: 1px solid #dee2e6;
            color: white; /* ✅ AGREGADO */
        }
        
        footer small {
            color: white; /* ✅ CAMBIADO de #6c757d a white */
            font-size: 0.875rem;
        }
    </style>
    
    <?php if (!empty($competencias_dinamicas)): ?>
    <style>
        /* Estilos dinámicos generados para cada competencia */
        <?php foreach ($competencias_dinamicas as $nombre => $data): ?>
        <?php 
            $safe_name = 'comp_' . md5($nombre); 
            $color = $data['color'];
            $background = generateBackgroundGradient($color);
        ?>
        .competencia-<?= $safe_name ?> {
            border-left: 4px solid <?= $color ?> !important;
            background: <?= $background ?> !important;
        }
        .competencia-<?= $safe_name ?> .progress-fill {
            background: linear-gradient(90deg, <?= $color ?>, <?= $color ?>DD) !important;
        }
        .competencia-<?= $safe_name ?> .competencia-score {
            color: <?= $color ?> !important;
            font-weight: 700;
        }
        <?php endforeach; ?>
    </style>
    <?php endif; ?>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>
                <i class="fas fa-chart-line"></i>
                Resultados del Examen
            </h1>
            <div class="user-info">
                <span><i class="fas fa-user"></i> <?= htmlspecialchars($participante_nombre) ?></span>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    Salir
                </a>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Mensaje especial si aplica -->
        <?php if ($mensaje_especial): ?>
            <div class="alert alert-<?= $mensaje_especial['tipo'] ?>" style="margin-bottom: 30px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-<?= $mensaje_especial['tipo'] === 'success' ? 'check-circle' : ($mensaje_especial['tipo'] === 'warning' ? 'exclamation-triangle' : 'info-circle') ?>" style="font-size: 2rem;"></i>
                    <div>
                        <h3 style="margin: 0 0 8px 0; color: inherit;"><?= $mensaje_especial['titulo'] ?></h3>
                        <p style="margin: 0; color: inherit;"><?= $mensaje_especial['mensaje'] ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($sin_resultados): ?>
            <div class="no-results">
                <i class="fas fa-clipboard-question"></i>
                <h2>Aún no has realizado el examen</h2>
                <p>Para ver tus resultados, primero debes completar tu examen de ingreso.</p>
                <?php if ($participante_info && $participante_info['nivel_examen']): ?>
                    <a href="examen.php" class="btn-take-exam">
                        <i class="fas fa-pen-fancy"></i>
                        Realizar Examen
                    </a>
                <?php else: ?>
                    <p style="margin-top: 16px; color: #856404;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Contacta al administrador para que te asigne un nivel de examen.
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Header con resultado principal -->
            <div class="result-header">
                <div class="status-badge status-<?= strtolower(str_replace(' ', '-', $estado_final)) ?>">
                    <?php
                    $iconos = [
                        'EXCELENTE' => 'fa-trophy',
                        'APROBADO' => 'fa-check-circle',
                        'REGULAR' => 'fa-exclamation-circle',
                        'NO APROBADO' => 'fa-times-circle'
                    ];
                    ?>
                    <i class="fas <?= $iconos[$estado_final] ?>"></i>
                    <?= $estado_final ?>
                </div>
                
                <div class="score-display"><?= number_format($porcentaje_general, 1) ?>%</div>
                
                <div class="participant-info">
                    <div class="info-card">
                        <label>Participante:</label>
                        <span><?= htmlspecialchars($participante_info['nombre']) ?></span>
                    </div>
                    <div class="info-card">
                        <label>Nivel del Examen:</label>
                        <span><?= ucfirst($participante_info['nivel_examen']) ?></span>
                    </div>
                    <div class="info-card">
                        <label>Fecha del Examen:</label>
                        <span><?= date('d/m/Y H:i', strtotime($fecha_examen)) ?></span>
                    </div>
                    <div class="info-card">
                        <label>Total de Preguntas:</label>
                        <span><?= $total_preguntas ?></span>
                    </div>
                </div>
            </div>

            <!-- Estadísticas resumidas -->
            <div class="summary-stats">
                <h3 class="section-title">
                    <i class="fas fa-chart-bar"></i>
                    Resumen de Resultados
                </h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-number"><?= $total_correctas ?></span>
                        <div class="stat-label">Respuestas Correctas</div>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?= $total_preguntas - $total_correctas ?></span>
                        <div class="stat-label">Respuestas Incorrectas</div>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?= count($resultados) ?></span>
                        <div class="stat-label">Competencias Evaluadas</div>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?= count(array_filter($resultados, function($r) { return isset($r['aprobado']) && $r['aprobado']; })) ?></span>
                        <div class="stat-label">Competencias Aprobadas</div>
                    </div>
                </div>
            </div>

            <!-- Resultados detallados -->
            <div class="results-grid">
                <div class="competencias-section">
                    <h3 class="section-title">
                        <i class="fas fa-list-check"></i>
                        Resultados por Competencia
                    </h3>
                    
                    <!-- Leyenda de colores dinámica -->
                    <?php if (!empty($competencias_dinamicas)): ?>
                    <div class="color-legend">
                        <div class="legend-title">
                            <i class="fas fa-palette"></i>
                            Código de colores por competencia
                        </div>
                        <div class="legend-items">
                            <?php foreach ($competencias_dinamicas as $nombre => $data): ?>
                            <div class="legend-item">
                                <span class="legend-color" style="background: <?= $data['color'] ?>;"></span>
                                <span><?= htmlspecialchars($nombre) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php foreach ($resultados as $resultado): ?>
    <?php 
        // Validación adicional por resultado
        $competencia_nombre = isset($resultado['competencia']) ? htmlspecialchars($resultado['competencia']) : 'Competencia Desconocida';
        $competencia_correctas = isset($resultado['correctas']) ? intval($resultado['correctas']) : 0;
        $competencia_total = isset($resultado['total_preguntas']) ? intval($resultado['total_preguntas']) : 0;
        $competencia_porcentaje = isset($resultado['porcentaje']) ? number_format(floatval($resultado['porcentaje']), 1) : '0.0';
        
        $safe_name = 'comp_' . md5($competencia_nombre);
        $color_class = 'competencia-' . $safe_name;
    ?>
    <div class="competencia-item <?= $color_class ?>">
        <div class="competencia-header">
            <span class="competencia-name"><?= $competencia_nombre ?></span>
            <span class="competencia-score">
                <?= $competencia_correctas ?>/<?= $competencia_total ?> (<?= $competencia_porcentaje ?>%)
            </span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" 
                 style="width: <?= $competencia_porcentaje ?>%"></div>
        </div>
    </div>
<?php endforeach; ?>
                </div>

                <div class="chart-container">
                    <h3 class="section-title">
                        <i class="fas fa-chart-pie"></i>
                        Distribución de Resultados
                    </h3>
                    <div id="chart-loading" class="chart-loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Cargando gráfico...</p>
                    </div>
                    <canvas id="resultsChart" width="400" height="400" style="display: none;"></canvas>
                </div>
            </div>
            
            <!-- Acciones disponibles -->
            <?php if ($estado_final === 'NO APROBADO'): ?>
                <div class="actions-section" style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #ff9800;">
                    <h3 style="color: #ef6c00; margin-bottom: 15px;">
                        <i class="fas fa-redo-alt"></i>
                        Opciones de Rehabilitación
                    </h3>
                    
                    <?php
                    // Verificar rehabilitaciones disponibles
                    $stmt_rehab_count = $pdo->prepare("
                        SELECT COUNT(*) as total_rehab, 
                               STRING_AGG(competencia_nombre, ', ') as competencias_pendientes
                        FROM rehabilitaciones_competencia 
                        WHERE participante_id = ? AND estado = 'ACTIVA'
                    ");
                    $stmt_rehab_count->execute([$participante_id]);
                    $rehab_info = $stmt_rehab_count->fetch(PDO::FETCH_ASSOC);
                    ?>
                    
                    <?php if ($rehab_info['total_rehab'] > 0): ?>
                        <div style="background: #fff3e0; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 3px solid #ff9800;">
                            <p style="margin: 0; color: #ef6c00;">
                                <i class="fas fa-info-circle"></i>
                                <strong>Tienes rehabilitaciones automáticas disponibles para:</strong><br>
                                <?= htmlspecialchars($rehab_info['competencias_pendientes']) ?>
                            </p>
                        </div>
                        
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <a href="examen.php?auto_rehab=1&from_result=1" 
                               style="background: #4CAF50; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; font-weight: 500;">
                                <i class="fas fa-play"></i>
                                Iniciar Rehabilitación Ahora
                            </a>
                            
                            <a href="ver_resultado.php?no_redirect=1&manual=1" 
                               style="background: #2196F3; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; font-weight: 500;">
                                <i class="fas fa-eye"></i>
                                Solo Ver Resultados
                            </a>
                        </div>
                    <?php else: ?>
                        <div style="background: #ffebee; padding: 15px; border-radius: 6px; border-left: 3px solid #f44336;">
                            <p style="margin: 0; color: #c62828;">
                                <i class="fas fa-exclamation-triangle"></i>
                                No tienes rehabilitaciones disponibles. Contacta al administrador.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!$sin_resultados): ?>
    <script>
    // Esperar a que la página esté completamente cargada
    window.addEventListener('load', function() {
        try {
            // Ocultar mensaje de carga y mostrar canvas
            const loadingElement = document.getElementById('chart-loading');
            const canvasElement = document.getElementById('resultsChart');
            
            if (loadingElement) loadingElement.style.display = 'none';
            if (canvasElement) canvasElement.style.display = 'block';
            
            // Verificar que tenemos datos válidos
            const competencias = <?= json_encode(array_column($resultados, 'competencia')) ?>;
            const porcentajes = <?= json_encode(array_column($resultados, 'porcentaje')) ?>;
            
            // Obtener colores dinámicos para cada competencia
            const competenciasColores = <?= json_encode($competencias_dinamicas) ?>;
            const coloresCompetencias = competencias.map(comp => competenciasColores[comp]?.color || '#95a5a6');
            
            // Validar que tenemos datos
            if (!competencias || !porcentajes || competencias.length === 0 || porcentajes.length === 0) {
                console.error('No hay datos suficientes para crear el gráfico');
                document.querySelector('.chart-container').innerHTML = `
                    <h3 class="section-title">
                        <i class="fas fa-chart-pie"></i>
                        Distribución de Resultados
                    </h3>
                    <p style="text-align: center; color: #666; padding: 40px;">
                        <i class="fas fa-info-circle"></i><br>
                        No hay datos suficientes para mostrar el gráfico
                    </p>`;
                return;
            }
            
            // Crear gráfico de resultados
            const ctx = document.getElementById('resultsChart');
            if (!ctx) {
                console.error('No se encontró el canvas para el gráfico');
                return;
            }
            
            const ctxContext = ctx.getContext('2d');
            
            // Usar los colores específicos de cada competencia
            const colores = coloresCompetencias;
            
            // Destruir gráfico existente si existe
            if (window.myChart) {
                window.myChart.destroy();
            }
            
            window.myChart = new Chart(ctxContext, {
                type: 'doughnut',
                data: {
                    labels: competencias,
                    datasets: [{
                        data: porcentajes,
                        backgroundColor: colores,
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1000
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed.toFixed(1) + '%';
                                }
                            }
                        }
                    }
                }
            });
            
        } catch (error) {
            console.error('Error al crear el gráfico:', error);
            
            // Mostrar error en lugar del gráfico
            const chartContainer = document.querySelector('.chart-container');
            if (chartContainer) {
                chartContainer.innerHTML = `
                    <h3 class="section-title">
                        <i class="fas fa-chart-pie"></i>
                        Distribución de Resultados
                    </h3>
                    <div style="text-align: center; color: #dc3545; padding: 40px;">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                        <p style="margin-top: 16px;">Error al cargar el gráfico</p>
                        <small style="color: #666;">Los datos están disponibles en la tabla de la izquierda</small>
                    </div>`;
            }
        }
        
        // Animar barras de progreso después de crear el gráfico
        setTimeout(() => {
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        }, 500);
        
        // Animar números
        const statNumbers = document.querySelectorAll('.stat-number');
        statNumbers.forEach(stat => {
            const target = parseInt(stat.textContent);
            if (isNaN(target)) return;
            
            let current = 0;
            const increment = target / 50;
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                stat.textContent = Math.round(current);
            }, 30);
        });
    });
    </script>
    <?php endif; ?>

    <?php require_once 'includes\footer.php'; ?>  <!-- ✅ CORRECTO -->
