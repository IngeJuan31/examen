<?php
// filepath: corregir_logica_completa.php
session_start();
require_once 'config/db.php';

$participante_id = $_SESSION['participante_id'] ?? 1;

echo "<h2>🔧 Corrigiendo Lógica Completa de Resultados</h2>";

try {
    $pdo->beginTransaction();
    
    echo "<h3>📊 1. Analizando datos actuales:</h3>";
    
    // Verificar historial
    $stmt_hist = $pdo->prepare("SELECT COUNT(*) FROM historial_examenes WHERE participante_id = ?");
    $stmt_hist->execute([$participante_id]);
    $count_historial = $stmt_hist->fetchColumn();
    
    // Verificar historial competencias
    $stmt_hist_comp = $pdo->prepare("
        SELECT COUNT(*) 
        FROM historial_competencias hc 
        JOIN historial_examenes he ON hc.historial_id = he.id_historial 
        WHERE he.participante_id = ?
    ");
    $stmt_hist_comp->execute([$participante_id]);
    $count_hist_comp = $stmt_hist_comp->fetchColumn();
    
    // Verificar resultados
    $stmt_res = $pdo->prepare("SELECT COUNT(*) FROM resultados WHERE participante_id = ?");
    $stmt_res->execute([$participante_id]);
    $count_resultados = $stmt_res->fetchColumn();
    
    // Verificar resultado_competencias
    $stmt_res_comp = $pdo->prepare("
        SELECT COUNT(*) 
        FROM resultado_competencias rc 
        JOIN resultados r ON rc.resultado_id = r.id_resultado 
        WHERE r.participante_id = ?
    ");
    $stmt_res_comp->execute([$participante_id]);
    $count_res_comp = $stmt_res_comp->fetchColumn();
    
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>Tabla</th><th>Registros</th><th>Estado</th></tr>";
    echo "<tr><td>historial_examenes</td><td>$count_historial</td><td>" . ($count_historial > 0 ? '✅' : '❌') . "</td></tr>";
    echo "<tr><td>historial_competencias</td><td>$count_hist_comp</td><td>" . ($count_hist_comp > 0 ? '✅' : '❌') . "</td></tr>";
    echo "<tr><td>resultados</td><td>$count_resultados</td><td>" . ($count_resultados > 0 ? '✅' : '❌') . "</td></tr>";
    echo "<tr><td>resultado_competencias</td><td>$count_res_comp</td><td>" . ($count_res_comp > 0 ? '✅' : '❌') . "</td></tr>";
    echo "</table>";
    
    if ($count_historial === 0) {
        throw new Exception("No hay historial para procesar");
    }
    
    echo "<h3>🔄 2. Creando/Actualizando tabla resultados:</h3>";
    
    // Obtener competencias consolidadas (último resultado de cada competencia)
    $stmt_consolidadas = $pdo->prepare("
        WITH competencias_consolidadas AS (
            SELECT DISTINCT 
                hc.competencia_id,
                hc.competencia_nombre,
                FIRST_VALUE(hc.total_preguntas) OVER (
                    PARTITION BY hc.competencia_id 
                    ORDER BY he.intento_numero DESC, he.fecha_realizacion DESC
                ) as total_preguntas,
                FIRST_VALUE(hc.respuestas_correctas) OVER (
                    PARTITION BY hc.competencia_id 
                    ORDER BY he.intento_numero DESC, he.fecha_realizacion DESC
                ) as respuestas_correctas,
                FIRST_VALUE(hc.porcentaje) OVER (
                    PARTITION BY hc.competencia_id 
                    ORDER BY he.intento_numero DESC, he.fecha_realizacion DESC
                ) as porcentaje,
                FIRST_VALUE(he.intento_numero) OVER (
                    PARTITION BY hc.competencia_id 
                    ORDER BY he.intento_numero DESC, he.fecha_realizacion DESC
                ) as ultimo_intento
            FROM historial_competencias hc
            JOIN historial_examenes he ON hc.historial_id = he.id_historial
            WHERE he.participante_id = ?
        )
        SELECT * FROM competencias_consolidadas
        ORDER BY competencia_nombre
    ");
    $stmt_consolidadas->execute([$participante_id]);
    $competencias_consolidadas = $stmt_consolidadas->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($competencias_consolidadas)) {
        throw new Exception("No se encontraron competencias consolidadas");
    }
    
    echo "<p>✅ Competencias consolidadas encontradas: " . count($competencias_consolidadas) . "</p>";
    
    // Calcular totales consolidados
    $total_preguntas_final = 0;
    $total_correctas_final = 0;
    
    foreach ($competencias_consolidadas as $comp) {
        $total_preguntas_final += $comp['total_preguntas'];
        $total_correctas_final += $comp['respuestas_correctas'];
    }
    
    $porcentaje_final = ($total_preguntas_final > 0) ? ($total_correctas_final / $total_preguntas_final) * 100 : 0;
    $total_incorrectas_final = $total_preguntas_final - $total_correctas_final;
    
    // Determinar estado final
    $competencias_aprobadas = count(array_filter($competencias_consolidadas, function($c) { 
        return $c['porcentaje'] >= 70; 
    }));
    $todas_aprobadas = $competencias_aprobadas === count($competencias_consolidadas);
    
    if ($todas_aprobadas && $porcentaje_final >= 70) {
        $estado_final = $porcentaje_final >= 85 ? 'EXCELENTE' : 'APROBADO';
    } else {
        $estado_final = 'NO APROBADO';
    }
    
    // Obtener fecha del último examen
    $stmt_fecha = $pdo->prepare("
        SELECT fecha_realizacion, nivel_examen 
        FROM historial_examenes 
        WHERE participante_id = ? 
        ORDER BY intento_numero DESC, fecha_realizacion DESC 
        LIMIT 1
    ");
    $stmt_fecha->execute([$participante_id]);
    $ultimo_examen = $stmt_fecha->fetch(PDO::FETCH_ASSOC);
    
    echo "<h3>📊 3. Datos consolidados calculados:</h3>";
    echo "<ul>";
    echo "<li><strong>Total preguntas:</strong> $total_preguntas_final</li>";
    echo "<li><strong>Respuestas correctas:</strong> $total_correctas_final</li>";
    echo "<li><strong>Respuestas incorrectas:</strong> $total_incorrectas_final</li>";
    echo "<li><strong>Porcentaje final:</strong> " . number_format($porcentaje_final, 2) . "%</li>";
    echo "<li><strong>Competencias aprobadas:</strong> $competencias_aprobadas / " . count($competencias_consolidadas) . "</li>";
    echo "<li><strong>Estado final:</strong> $estado_final</li>";
    echo "</ul>";
    
    // Eliminar resultado anterior si existe
    $stmt_delete_res_comp = $pdo->prepare("
        DELETE FROM resultado_competencias 
        WHERE resultado_id IN (SELECT id_resultado FROM resultados WHERE participante_id = ?)
    ");
    $stmt_delete_res_comp->execute([$participante_id]);
    
    $stmt_delete_res = $pdo->prepare("DELETE FROM resultados WHERE participante_id = ?");
    $stmt_delete_res->execute([$participante_id]);
    
    echo "<p>🗑️ Resultados anteriores eliminados</p>";
    
    // Crear nuevo resultado consolidado
    $stmt_crear_resultado = $pdo->prepare("
        INSERT INTO resultados (
            participante_id, total_preguntas, respuestas_correctas, respuestas_incorrectas,
            puntaje_total, porcentaje, nivel_examen, tiempo_total_segundos,
            fecha_realizacion, estado
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id_resultado
    ");
    
    $stmt_crear_resultado->execute([
        $participante_id,
        $total_preguntas_final,
        $total_correctas_final,
        $total_incorrectas_final,
        $porcentaje_final, // puntaje_total = porcentaje
        $porcentaje_final,
        $ultimo_examen['nivel_examen'] ?? 'bajo',
        0, // tiempo_total_segundos (no disponible en historial)
        $ultimo_examen['fecha_realizacion'],
        strtolower($estado_final)
    ]);
    
    $resultado_id = $pdo->lastInsertId();
    
    echo "<p>✅ Resultado principal creado con ID: $resultado_id</p>";
    
    // Crear registros en resultado_competencias
    echo "<h3>🎯 4. Creando resultado_competencias:</h3>";
    
    foreach ($competencias_consolidadas as $comp) {
        $stmt_crear_comp = $pdo->prepare("
            INSERT INTO resultado_competencias (
                resultado_id, competencia_id, total_preguntas, 
                respuestas_correctas, porcentaje
            ) VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt_crear_comp->execute([
            $resultado_id,
            $comp['competencia_id'],
            $comp['total_preguntas'],
            $comp['respuestas_correctas'],
            $comp['porcentaje']
        ]);
        
        echo "<p>✅ {$comp['competencia_nombre']}: {$comp['respuestas_correctas']}/{$comp['total_preguntas']} (" . number_format($comp['porcentaje'], 1) . "%)</p>";
    }
    
    $pdo->commit();
    
    echo "<div style='background: #e8f5e9; padding: 20px; border-left: 4px solid #4caf50; margin: 30px 0; border-radius: 8px;'>";
    echo "<h3>✅ CORRECCIÓN COMPLETADA EXITOSAMENTE</h3>";
    echo "<p><strong>Sistema ahora funciona correctamente:</strong></p>";
    echo "<ul>";
    echo "<li>✅ Historial de intentos preservado</li>";
    echo "<li>✅ Resultados consolidados creados</li>";
    echo "<li>✅ Competencias consolidadas (último resultado de cada una)</li>";
    echo "<li>✅ Sistema dual funcionando correctamente</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<h3>🎯 Verificar corrección:</h3>";
    echo "<div style='margin: 30px 0;'>";
    echo "<a href='verificar_historial_completo.php' style='background: #2196F3; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; margin-right: 15px;'>🔍 Verificar Datos</a>";
    echo "<a href='ver_resultado.php?manual=1&debug=1' style='background: #4CAF50; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; margin-right: 15px;'>👁️ Ver Resultados</a>";
    echo "<a href='examen.php?tipo=rehabilitacion' style='background: #FF9800; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px;'>🔄 Hacer Rehabilitación</a>";
    echo "</div>";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<div style='background: #ffebee; padding: 20px; border-left: 4px solid #f44336; margin: 20px 0; border-radius: 8px;'>";
    echo "<h4>❌ ERROR</h4>";
    echo "<p><strong>Mensaje:</strong> " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>