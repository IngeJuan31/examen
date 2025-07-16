<?php
require_once __DIR__ . '/../config/db.php';

function calcularYGuardarResultado($participante_id, $pdo) {
    try {
        error_log("=== INICIO CÁLCULO RESULTADO ===");
        error_log("Participante ID: $participante_id");
        
        // PASO 1: Verificar rehabilitaciones
        $stmt_rehab_check = $pdo->prepare("
            SELECT COUNT(*) as tiene_rehabilitaciones,
                   STRING_AGG(competencia_id::text, ',') as competencias_rehabilitadas
            FROM rehabilitaciones_competencia 
            WHERE participante_id = ? AND estado = 'ACTIVA'
        ");
        $stmt_rehab_check->execute([$participante_id]);
        $rehab_info = $stmt_rehab_check->fetch(PDO::FETCH_ASSOC);
        
        $es_rehabilitacion = $rehab_info['tiene_rehabilitaciones'] > 0;
        error_log("Es rehabilitación: " . ($es_rehabilitacion ? 'SÍ' : 'NO'));
        
        // PASO 1.5: Obtener último intento
        $stmt_ultimo_intento = $pdo->prepare("
            SELECT COALESCE(MAX(intento_numero), 0) as ultimo_intento 
            FROM historial_examenes 
            WHERE participante_id = ?
        ");
        $stmt_ultimo_intento->execute([$participante_id]);
        $ultimo_intento = $stmt_ultimo_intento->fetchColumn();
        error_log("Último intento: $ultimo_intento");
        
        // PASO 2: Obtener respuestas del participante con nombres de competencias
        $stmt_respuestas = $pdo->prepare("
            SELECT 
                r.id_pregunta, 
                r.id_opcion, 
                o.es_correcta, 
                p.id_competencia,
                c.nombre as competencia_nombre
            FROM respuestas r
            JOIN opciones o ON r.id_opcion = o.id_opcion
            JOIN preguntas p ON r.id_pregunta = p.id_pregunta
            JOIN competencias c ON p.id_competencia = c.id_competencia
            WHERE r.id_participante = ?
        ");
        $stmt_respuestas->execute([$participante_id]);
        $respuestas = $stmt_respuestas->fetchAll(PDO::FETCH_ASSOC);
        
        $total_respuestas = count($respuestas);
        error_log("Total respuestas encontradas: $total_respuestas");
        
        if ($total_respuestas == 0) {
            return ['status' => 'error', 'message' => 'No se encontraron respuestas para calcular'];
        }
        
        // PASO 3: Procesar respuestas por competencia
        $competencias_stats = [];
        $total_correctas = 0;
        
        foreach ($respuestas as $resp) {
            $comp_id = $resp['id_competencia'];
            $comp_nombre = $resp['competencia_nombre'];
            $es_correcta = $resp['es_correcta'];
            
            // Inicializar competencia si no existe
            if (!isset($competencias_stats[$comp_id])) {
                $competencias_stats[$comp_id] = [
                    'nombre' => $comp_nombre,
                    'total' => 0,
                    'correctas' => 0
                ];
            }
            
            $competencias_stats[$comp_id]['total']++;
            if ($es_correcta) {
                $competencias_stats[$comp_id]['correctas']++;
                $total_correctas++;
            }
        }
        
        error_log("COMPETENCIAS PROCESADAS: " . count($competencias_stats));
        
        // PASO 4: Calcular porcentajes por competencia
        $competencias_data = [];
        $todas_competencias_aprobadas = true;
        
        foreach ($competencias_stats as $comp_id => $stats) {
            $porcentaje = ($stats['total'] > 0) ? ($stats['correctas'] / $stats['total']) * 100 : 0;
            
            $competencias_data[$comp_id] = [
                'id_competencia' => $comp_id,
                'nombre' => $stats['nombre'],
                'total_preguntas' => $stats['total'],
                'respuestas_correctas' => $stats['correctas'],
                'porcentaje' => $porcentaje
            ];
            
            if ($porcentaje < 70) {
                $todas_competencias_aprobadas = false;
            }
            
            error_log("COMPETENCIA {$stats['nombre']}: {$stats['correctas']}/{$stats['total']} = " . number_format($porcentaje, 1) . "%");
        }
        
        // PASO 5: Calcular porcentaje general y estado final
        $total_preguntas = $total_respuestas;
        $porcentaje_general = ($total_correctas / $total_preguntas) * 100;
        
        // Determinar estado final
        $estado_final = ($todas_competencias_aprobadas && $porcentaje_general >= 70) ? 'APROBADO' : 'REPROBADO';
        
        error_log("Cálculo completado:");
        error_log("  - Total preguntas: $total_preguntas");
        error_log("  - Respuestas correctas: $total_correctas");
        error_log("  - Porcentaje general: " . round($porcentaje_general, 1) . "%");
        error_log("  - Estado final: $estado_final");
        error_log("  - Todas competencias aprobadas: " . ($todas_competencias_aprobadas ? 'SÍ' : 'NO'));
        
        // PASO 6: Iniciar transacción y guardar en base de datos
        $pdo->beginTransaction();
        
        // PASO 6.1: Limpiar resultados anteriores
        $stmt_del_resultado = $pdo->prepare("DELETE FROM resultados WHERE participante_id = ?");
        $stmt_del_resultado->execute([$participante_id]);
        
        // PASO 6.2: Insertar nuevo resultado
        $respuestas_incorrectas = $total_preguntas - $total_correctas;
        $stmt_resultado = $pdo->prepare("
            INSERT INTO resultados (
                participante_id, 
                total_preguntas, 
                respuestas_correctas, 
                respuestas_incorrectas,
                puntaje_total,
                porcentaje, 
                estado, 
                fecha_realizacion
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $result_insert = $stmt_resultado->execute([
            $participante_id, 
            $total_preguntas, 
            $total_correctas, 
            $respuestas_incorrectas,
            $total_correctas,
            $porcentaje_general, 
            $estado_final
        ]);
        
        if (!$result_insert) {
            throw new Exception('Error al insertar el resultado principal');
        }
        
        $resultado_id = $pdo->lastInsertId();
        error_log("Resultado principal guardado con ID: $resultado_id");
        
        // PASO 6.3: Guardar resultados por competencia
        foreach ($competencias_data as $comp_id => $comp_data) {
            $stmt_comp_resultado = $pdo->prepare("
                INSERT INTO resultado_competencias (
                    resultado_id, 
                    competencia_id, 
                    total_preguntas, 
                    respuestas_correctas, 
                    porcentaje
                )
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt_comp_resultado->execute([
                $resultado_id, 
                $comp_id, 
                $comp_data['total_preguntas'], 
                $comp_data['respuestas_correctas'], 
                $comp_data['porcentaje']
            ]);
        }
        
        // PASO 7: GUARDAR EN HISTORIAL
        $intento_numero = $ultimo_intento + 1;
        
        $stmt_historial = $pdo->prepare("
            INSERT INTO historial_examenes (
                participante_id, 
                intento_numero,
                total_preguntas, 
                respuestas_correctas, 
                respuestas_incorrectas,
                porcentaje, 
                nivel_examen,
                estado, 
                fecha_realizacion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            RETURNING id_historial
        ");
        
        $stmt_historial->execute([
            $participante_id,
            $intento_numero,
            $total_preguntas,
            $total_correctas,
            $total_preguntas - $total_correctas,
            $porcentaje_general,
            'medio', // nivel_examen por defecto
            $estado_final
        ]);
        
        $historial_id = $pdo->lastInsertId();
        error_log("HISTORIAL CREADO - ID: $historial_id, Intento: $intento_numero");
        
        // PASO 7.1: GUARDAR HISTORIAL POR COMPETENCIAS
        if (!empty($competencias_stats) && $historial_id) {
            error_log("GUARDANDO HISTORIAL POR COMPETENCIAS - Total: " . count($competencias_stats));
            
            foreach ($competencias_stats as $comp_id => $stats) {
                $comp_porcentaje = ($stats['total'] > 0) ? ($stats['correctas'] / $stats['total']) * 100 : 0;
                $comp_estado = $comp_porcentaje >= 70 ? 'APROBADO' : 'REPROBADO';
                
                $stmt_hist_comp = $pdo->prepare("
                    INSERT INTO historial_competencias (
                        historial_id, competencia_id, competencia_nombre,
                        total_preguntas, respuestas_correctas, porcentaje, estado
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $result_comp = $stmt_hist_comp->execute([
                    $historial_id,
                    $comp_id,
                    $stats['nombre'],
                    $stats['total'],
                    $stats['correctas'],
                    $comp_porcentaje,
                    $comp_estado
                ]);
                
                if ($result_comp) {
                    error_log("HISTORIAL COMPETENCIA GUARDADO - {$stats['nombre']}: {$stats['correctas']}/{$stats['total']} ({$comp_porcentaje}%)");
                } else {
                    error_log("ERROR GUARDANDO HISTORIAL COMPETENCIA - {$stats['nombre']}");
                }
            }
        }
        
        // PASO 8: Si es rehabilitación, marcar como UTILIZADA
        if ($es_rehabilitacion && !empty($rehab_info['competencias_rehabilitadas'])) {
            $competencias_rehab_array = explode(',', $rehab_info['competencias_rehabilitadas']);
            
            foreach ($competencias_rehab_array as $comp_id) {
                $comp_id = trim($comp_id);
                if (!empty($comp_id)) {
                    $stmt_marcar_utilizada = $pdo->prepare("
                        UPDATE rehabilitaciones_competencia 
                        SET estado = 'UTILIZADA', fecha_utilizacion = CURRENT_TIMESTAMP 
                        WHERE participante_id = ? AND competencia_id = ? AND estado = 'ACTIVA'
                    ");
                    $stmt_marcar_utilizada->execute([$participante_id, $comp_id]);
                    error_log("Rehabilitación marcada como UTILIZADA - Competencia: $comp_id");
                }
            }
        }
        
        // PASO 9: CREAR REHABILITACIONES AUTOMÁTICAS PARA COMPETENCIAS REPROBADAS
        $competencias_para_rehabilitar = [];
        
        // Buscar competencias reprobadas (menos del 70%)
        foreach ($competencias_data as $comp_id => $comp_data) {
            if ($comp_data['porcentaje'] < 70) {
                $competencias_para_rehabilitar[] = [
                    'id_competencia' => $comp_id,
                    'nombre' => $comp_data['nombre'],
                    'porcentaje' => round($comp_data['porcentaje'], 1),
                    'respuestas_correctas' => $comp_data['respuestas_correctas'],
                    'total_preguntas' => $comp_data['total_preguntas']
                ];
                error_log("COMPETENCIA REPROBADA - {$comp_data['nombre']}: " . round($comp_data['porcentaje'], 1) . "%");
            }
        }
        
        // Si hay competencias reprobadas, crear rehabilitaciones automáticas
        if (!empty($competencias_para_rehabilitar)) {
            error_log("CREANDO REHABILITACIONES AUTOMÁTICAS - Total: " . count($competencias_para_rehabilitar));
            
            // Primero limpiar rehabilitaciones anteriores SOLO si no es una rehabilitación
            if (!$es_rehabilitacion) {
                $stmt_clean_rehab = $pdo->prepare("
                    DELETE FROM rehabilitaciones_competencia 
                    WHERE participante_id = ? AND estado = 'ACTIVA'
                ");
                $stmt_clean_rehab->execute([$participante_id]);
                error_log("REHABILITACIONES ANTERIORES LIMPIADAS");
            }
            
            foreach ($competencias_para_rehabilitar as $comp) {
                // Verificar si ya existe una rehabilitación activa para esta competencia
                $stmt_check_existing = $pdo->prepare("
                    SELECT COUNT(*) FROM rehabilitaciones_competencia 
                    WHERE participante_id = ? AND competencia_id = ? AND estado = 'ACTIVA'
                ");
                $stmt_check_existing->execute([$participante_id, $comp['id_competencia']]);
                $ya_existe = $stmt_check_existing->fetchColumn() > 0;
                
                if (!$ya_existe) {
                    // Crear nueva rehabilitación automática
                    $stmt_rehab = $pdo->prepare("
                        INSERT INTO rehabilitaciones_competencia (
                            participante_id, 
                            competencia_id, 
                            competencia_nombre, 
                            admin_id,
                            admin_nombre, 
                            intento_anterior, 
                            motivo, 
                            estado, 
                            fecha_rehabilitacion
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    
                    $motivo = "Rehabilitación automática por obtener {$comp['porcentaje']}% (menos del 70% requerido)";
                    
                    $resultado_rehab = $stmt_rehab->execute([
                        $participante_id,
                        $comp['id_competencia'],
                        $comp['nombre'],
                        1, // admin_id
                        'Sistema Automático',
                        $intento_numero,
                        $motivo,
                        'ACTIVA'
                    ]);
                    
                    if ($resultado_rehab) {
                        error_log("✅ REHABILITACIÓN CREADA - Competencia: {$comp['nombre']}");
                    } else {
                        error_log("❌ ERROR creando rehabilitación para: {$comp['nombre']}");
                    }
                } else {
                    error_log("⚠️ REHABILITACIÓN YA EXISTE - Competencia: {$comp['nombre']}");
                }
            }
            
            // Actualizar estado del participante a REHABILITADO si hay rehabilitaciones creadas
            if (!empty($competencias_para_rehabilitar)) {
                $stmt_update_estado = $pdo->prepare("
                    UPDATE participantes 
                    SET estado_examen = 'REHABILITADO' 
                    WHERE id_participante = ?
                ");
                $stmt_update_estado->execute([$participante_id]);
                
                error_log("🔄 ESTADO ACTUALIZADO - Participante marcado como REHABILITADO");
                
                // Actualizar el estado_final para reflejar el cambio
                $estado_final = 'REHABILITADO';
            }
        }
        
        // PASO 10: MANEJAR REHABILITACIONES COMPLETADAS
        if ($es_rehabilitacion) {
            error_log("PROCESANDO REHABILITACIÓN COMPLETADA");
            
            // Verificar si quedan rehabilitaciones activas
            $stmt_check_remaining = $pdo->prepare("
                SELECT COUNT(*) FROM rehabilitaciones_competencia 
                WHERE participante_id = ? AND estado = 'ACTIVA'
            ");
            $stmt_check_remaining->execute([$participante_id]);
            $rehabilitaciones_restantes = $stmt_check_remaining->fetchColumn();
            
            error_log("REHABILITACIONES RESTANTES: $rehabilitaciones_restantes");
            
            // Si no quedan rehabilitaciones activas, determinar estado final
            if ($rehabilitaciones_restantes == 0) {
                $nuevo_estado = ($todas_competencias_aprobadas && $porcentaje_general >= 70) ? 'APROBADO' : 'REPROBADO';
                
                $stmt_final = $pdo->prepare("
                    UPDATE participantes 
                    SET estado_examen = ? 
                    WHERE id_participante = ?
                ");
                $stmt_final->execute([$nuevo_estado, $participante_id]);
                
                error_log("ESTADO FINAL DESPUÉS DE REHABILITACIÓN: $nuevo_estado");
                $estado_final = $nuevo_estado;
            }
        } else {
            // Actualizar estado del participante para exámenes normales
            $stmt_update_participante = $pdo->prepare("
                UPDATE participantes 
                SET estado_examen = ? 
                WHERE id_participante = ?
            ");
            $stmt_update_participante->execute([$estado_final, $participante_id]);
        }
        
        $pdo->commit();
        error_log("=== CÁLCULO COMPLETADO EXITOSAMENTE ===");
        
        // **PREPARAR DATOS DE RETORNO**
        $competencias_resultado = [];
        foreach ($competencias_data as $comp_data) {
            $competencias_resultado[] = [
                'competencia' => $comp_data['nombre'],
                'total_preguntas' => $comp_data['total_preguntas'],
                'correctas' => $comp_data['respuestas_correctas'],
                'porcentaje' => round($comp_data['porcentaje'], 1)
            ];
        }
        
        return [
            'status' => 'success',
            'data' => [
                'total_preguntas' => $total_preguntas,
                'respuestas_correctas' => $total_correctas,
                'porcentaje' => round($porcentaje_general, 1),
                'estado' => $estado_final,
                'competencias' => $competencias_resultado,
                'es_rehabilitacion' => $es_rehabilitacion,
                'competencias_rehabilitadas' => count($competencias_para_rehabilitar),
                'historial_id' => $historial_id,
                'intento_numero' => $intento_numero
            ]
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("❌ ERROR CRÍTICO en calcularYGuardarResultado: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// Manejar llamadas directas al script
if (isset($_GET['id_participante']) || isset($_POST['id_participante'])) {
    $participante_id = $_GET['id_participante'] ?? $_POST['id_participante'];
    
    if (!$participante_id) {
        echo json_encode(['status' => 'error', 'message' => 'Falta id_participante']);
        exit;
    }
    
    $resultado = calcularYGuardarResultado($participante_id, $pdo);
    
    header('Content-Type: application/json');
    echo json_encode($resultado);
    exit;
}
?>

