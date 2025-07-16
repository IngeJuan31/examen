<?php
/**
 * Script para Limpiar Completamente la Base de Datos
 * 
 * Este script elimina TODOS los participantes y sus dependencias:
 * - Respuestas
 * - Rehabilitaciones (manuales y automáticas)
 * - Historial de exámenes
 * - Historial de competencias
 * - Asignaciones de examen
 * - Resultados
 * - Resultado por competencias
 */

require_once '../config/db.php';

echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .header { background: linear-gradient(135deg, #f44336, #d32f2f); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
    .warning { background: #fff3e0; border: 2px solid #ff9800; border-radius: 8px; padding: 20px; margin: 20px 0; }
    .section { margin: 20px 0; padding: 15px; border-left: 4px solid #2196F3; background: #f8f9fa; }
    .success { border-left-color: #4CAF50; background: #e8f5e9; }
    .error { border-left-color: #f44336; background: #ffebee; }
    .info { border-left-color: #2196F3; background: #e3f2fd; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
    th { background: #f5f5f5; }
    .btn { background: #f44336; color: white; padding: 15px 30px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin: 10px; }
    .btn:hover { background: #d32f2f; }
    .btn-cancel { background: #666; }
    .btn-cancel:hover { background: #555; }
</style>";

echo "<div class='container'>";
echo "<div class='header'>";
echo "<h1>🗑️ Limpieza Completa de la Base de Datos</h1>";
echo "<p>Eliminar TODOS los participantes y sus dependencias</p>";
echo "</div>";

// Verificar si se confirmó la eliminación
if (isset($_POST['confirmar_limpieza']) && $_POST['confirmar_limpieza'] === 'SI_ELIMINAR_TODO') {
    
    echo "<div class='section info'>";
    echo "<h2>🔄 Procesando Limpieza Completa...</h2>";
    echo "</div>";
    
    try {
        $pdo->beginTransaction();
        
        // Contadores para mostrar estadísticas
        $stats = [];
        
        // 1. Eliminar respuestas
        echo "<div class='section'>";
        echo "<h3>1️⃣ Eliminando Respuestas</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) FROM respuestas");
        $count_respuestas = $stmt->fetchColumn();
        
        if ($count_respuestas > 0) {
            $pdo->exec("DELETE FROM respuestas");
            echo "<p>✅ Eliminadas <strong>$count_respuestas</strong> respuestas</p>";
            $stats['respuestas'] = $count_respuestas;
        } else {
            echo "<p>ℹ️ No hay respuestas para eliminar</p>";
            $stats['respuestas'] = 0;
        }
        echo "</div>";
        
        // 2. Eliminar historial de competencias
        echo "<div class='section'>";
        echo "<h3>2️⃣ Eliminando Historial de Competencias</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) FROM historial_competencias");
        $count_historial_comp = $stmt->fetchColumn();
        
        if ($count_historial_comp > 0) {
            $pdo->exec("DELETE FROM historial_competencias");
            echo "<p>✅ Eliminados <strong>$count_historial_comp</strong> registros de historial por competencia</p>";
            $stats['historial_competencias'] = $count_historial_comp;
        } else {
            echo "<p>ℹ️ No hay historial de competencias para eliminar</p>";
            $stats['historial_competencias'] = 0;
        }
        echo "</div>";
        
        // 3. Eliminar historial de exámenes
        echo "<div class='section'>";
        echo "<h3>3️⃣ Eliminando Historial de Exámenes</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) FROM historial_examenes");
        $count_historial_ex = $stmt->fetchColumn();
        
        if ($count_historial_ex > 0) {
            $pdo->exec("DELETE FROM historial_examenes");
            echo "<p>✅ Eliminados <strong>$count_historial_ex</strong> registros de historial de exámenes</p>";
            $stats['historial_examenes'] = $count_historial_ex;
        } else {
            echo "<p>ℹ️ No hay historial de exámenes para eliminar</p>";
            $stats['historial_examenes'] = 0;
        }
        echo "</div>";
        
        // 4. Eliminar rehabilitaciones por competencia (automáticas)
        echo "<div class='section'>";
        echo "<h3>4️⃣ Eliminando Rehabilitaciones Automáticas</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) FROM rehabilitaciones_competencia");
        $count_rehab_comp = $stmt->fetchColumn();
        
        if ($count_rehab_comp > 0) {
            $pdo->exec("DELETE FROM rehabilitaciones_competencia");
            echo "<p>✅ Eliminadas <strong>$count_rehab_comp</strong> rehabilitaciones automáticas</p>";
            $stats['rehabilitaciones_competencia'] = $count_rehab_comp;
        } else {
            echo "<p>ℹ️ No hay rehabilitaciones automáticas para eliminar</p>";
            $stats['rehabilitaciones_competencia'] = 0;
        }
        echo "</div>";
        
        // 5. Verificar si existe tabla rehabilitaciones (opcional)
        echo "<div class='section'>";
        echo "<h3>5️⃣ Verificando Rehabilitaciones Adicionales</h3>";
        
        try {
            // Verificar si existe la tabla rehabilitaciones
            $stmt = $pdo->query("SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = 'rehabilitaciones'
            )");
            $tabla_existe = $stmt->fetchColumn();
            
            if ($tabla_existe) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM rehabilitaciones");
                $count_rehab_manual = $stmt->fetchColumn();
                
                if ($count_rehab_manual > 0) {
                    $pdo->exec("DELETE FROM rehabilitaciones");
                    echo "<p>✅ Eliminadas <strong>$count_rehab_manual</strong> rehabilitaciones adicionales</p>";
                    $stats['rehabilitaciones_adicionales'] = $count_rehab_manual;
                } else {
                    echo "<p>ℹ️ No hay rehabilitaciones adicionales para eliminar</p>";
                    $stats['rehabilitaciones_adicionales'] = 0;
                }
            } else {
                echo "<p>ℹ️ No existe tabla de rehabilitaciones adicionales</p>";
                $stats['rehabilitaciones_adicionales'] = 0;
            }
        } catch (Exception $e) {
            echo "<p>ℹ️ No se encontraron rehabilitaciones adicionales</p>";
            $stats['rehabilitaciones_adicionales'] = 0;
        }
        echo "</div>";
        
        // 6. Eliminar resultado por competencias
        echo "<div class='section'>";
        echo "<h3>6️⃣ Eliminando Resultados por Competencia</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) FROM resultado_competencias");
        $count_result_comp = $stmt->fetchColumn();
        
        if ($count_result_comp > 0) {
            $pdo->exec("DELETE FROM resultado_competencias");
            echo "<p>✅ Eliminados <strong>$count_result_comp</strong> resultados por competencia</p>";
            $stats['resultado_competencias'] = $count_result_comp;
        } else {
            echo "<p>ℹ️ No hay resultados por competencia para eliminar</p>";
            $stats['resultado_competencias'] = 0;
        }
        echo "</div>";
        
        // 7. Eliminar resultados
        echo "<div class='section'>";
        echo "<h3>7️⃣ Eliminando Resultados</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) FROM resultados");
        $count_resultados = $stmt->fetchColumn();
        
        if ($count_resultados > 0) {
            $pdo->exec("DELETE FROM resultados");
            echo "<p>✅ Eliminados <strong>$count_resultados</strong> resultados</p>";
            $stats['resultados'] = $count_resultados;
        } else {
            echo "<p>ℹ️ No hay resultados para eliminar</p>";
            $stats['resultados'] = 0;
        }
        echo "</div>";
        
        // 8. Eliminar asignaciones de examen
        echo "<div class='section'>";
        echo "<h3>8️⃣ Eliminando Asignaciones de Examen</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) FROM asignaciones_examen");
        $count_asignaciones = $stmt->fetchColumn();
        
        if ($count_asignaciones > 0) {
            $pdo->exec("DELETE FROM asignaciones_examen");
            echo "<p>✅ Eliminadas <strong>$count_asignaciones</strong> asignaciones de examen</p>";
            $stats['asignaciones_examen'] = $count_asignaciones;
        } else {
            echo "<p>ℹ️ No hay asignaciones de examen para eliminar</p>";
            $stats['asignaciones_examen'] = 0;
        }
        echo "</div>";
        
        // 9. FINALMENTE: Eliminar participantes
        echo "<div class='section'>";
        echo "<h3>9️⃣ Eliminando Participantes</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) FROM participantes");
        $count_participantes = $stmt->fetchColumn();
        
        if ($count_participantes > 0) {
            $pdo->exec("DELETE FROM participantes");
            echo "<p>✅ Eliminados <strong>$count_participantes</strong> participantes</p>";
            $stats['participantes'] = $count_participantes;
        } else {
            echo "<p>ℹ️ No hay participantes para eliminar</p>";
            $stats['participantes'] = 0;
        }
        echo "</div>";
        
        // 10. Reiniciar secuencias (auto-increment)
        echo "<div class='section'>";
        echo "<h3>🔄 Reiniciando Secuencias</h3>";
        
        $tablas_secuencias = [
            'participantes' => 'id_participante',
            'respuestas' => 'id_respuesta',
            'resultados' => 'id_resultado',
            'asignaciones_examen' => 'id_asignacion',
            'historial_examenes' => 'id_historial',
            'historial_competencias' => 'id_historial_competencia',
            'rehabilitaciones_competencia' => 'id_rehabilitacion_competencia'
        ];
        
        foreach ($tablas_secuencias as $tabla => $columna) {
            try {
                $pdo->exec("ALTER SEQUENCE {$tabla}_{$columna}_seq RESTART WITH 1");
                echo "<p>✅ Secuencia de <strong>$tabla</strong> reiniciada</p>";
            } catch (Exception $e) {
                echo "<p>⚠️ No se pudo reiniciar secuencia de $tabla: " . $e->getMessage() . "</p>";
            }
        }
        echo "</div>";
        
        $pdo->commit();
        
        // Resumen final
        echo "<div class='section success'>";
        echo "<h2>🎉 Limpieza Completada Exitosamente</h2>";
        echo "<table>";
        echo "<tr><th>Elemento</th><th>Registros Eliminados</th></tr>";
        
        $total_eliminados = 0;
        foreach ($stats as $elemento => $cantidad) {
            echo "<tr>";
            echo "<td>" . ucwords(str_replace('_', ' ', $elemento)) . "</td>";
            echo "<td><strong>$cantidad</strong></td>";
            echo "</tr>";
            $total_eliminados += $cantidad;
        }
        
        echo "<tr style='background: #e8f5e9; font-weight: bold;'>";
        echo "<td><strong>TOTAL ELIMINADO</strong></td>";
        echo "<td><strong>$total_eliminados registros</strong></td>";
        echo "</tr>";
        echo "</table>";
        
        echo "<h3>✅ La base de datos está completamente limpia</h3>";
        echo "<p>Todos los participantes y sus dependencias han sido eliminados correctamente. El sistema está listo para nuevos registros.</p>";
        echo "</div>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='section error'>";
        echo "<h2>❌ Error Durante la Limpieza</h2>";
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>La transacción ha sido revertida. Los datos permanecen intactos.</p>";
        echo "</div>";
    }
    
} else {
    // Mostrar información actual y formulario de confirmación
    echo "<div class='warning'>";
    echo "<h2>⚠️ ADVERTENCIA CRÍTICA</h2>";
    echo "<p><strong>Esta acción es IRREVERSIBLE y eliminará TODOS los datos de participantes:</strong></p>";
    echo "<ul>";
    echo "<li>🗑️ Todos los participantes registrados</li>";
    echo "<li>📝 Todas las respuestas de exámenes</li>";
    echo "<li>📊 Todo el historial de exámenes</li>";
    echo "<li>🎯 Todo el historial por competencias</li>";
    echo "<li>🔄 Todas las rehabilitaciones (automáticas y manuales)</li>";
    echo "<li>📈 Todos los resultados y calificaciones</li>";
    echo "<li>📋 Todas las asignaciones de examen</li>";
    echo "</ul>";
    echo "<p><strong style='color: #d32f2f;'>Una vez confirmado, NO se puede deshacer esta operación.</strong></p>";
    echo "</div>";
    
    // Mostrar estadísticas actuales
    echo "<div class='section info'>";
    echo "<h2>📊 Estado Actual de la Base de Datos</h2>";
    
    try {
        $stats_actuales = [];
        $stats_actuales['Participantes'] = $pdo->query("SELECT COUNT(*) FROM participantes")->fetchColumn();
        $stats_actuales['Respuestas'] = $pdo->query("SELECT COUNT(*) FROM respuestas")->fetchColumn();
        $stats_actuales['Historial Exámenes'] = $pdo->query("SELECT COUNT(*) FROM historial_examenes")->fetchColumn();
        $stats_actuales['Historial Competencias'] = $pdo->query("SELECT COUNT(*) FROM historial_competencias")->fetchColumn();
        $stats_actuales['Rehabilitaciones Automáticas'] = $pdo->query("SELECT COUNT(*) FROM rehabilitaciones_competencia")->fetchColumn();
        $stats_actuales['Resultados'] = $pdo->query("SELECT COUNT(*) FROM resultados")->fetchColumn();
        $stats_actuales['Asignaciones'] = $pdo->query("SELECT COUNT(*) FROM asignaciones_examen")->fetchColumn();
        
        echo "<table>";
        echo "<tr><th>Elemento</th><th>Registros Actuales</th></tr>";
        
        $total_actual = 0;
        foreach ($stats_actuales as $elemento => $cantidad) {
            echo "<tr>";
            echo "<td>$elemento</td>";
            echo "<td><strong>$cantidad</strong></td>";
            echo "</tr>";
            $total_actual += $cantidad;
        }
        
        echo "<tr style='background: #fff3e0; font-weight: bold;'>";
        echo "<td><strong>TOTAL A ELIMINAR</strong></td>";
        echo "<td><strong>$total_actual registros</strong></td>";
        echo "</tr>";
        echo "</table>";
        
    } catch (Exception $e) {
        echo "<p>Error al obtener estadísticas: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo "</div>";
    
    // Formulario de confirmación
    echo "<div class='section'>";
    echo "<h2>🔐 Confirmación Requerida</h2>";
    echo "<form method='POST' onsubmit='return confirmarEliminacion()'>";
    echo "<p><strong>Para confirmar la eliminación completa, escriba exactamente:</strong> <code>SI_ELIMINAR_TODO</code></p>";
    echo "<input type='text' name='confirmar_limpieza' placeholder='Escriba: SI_ELIMINAR_TODO' style='padding: 10px; width: 300px; margin: 10px 0;' required>";
    echo "<br>";
    echo "<button type='submit' class='btn'>🗑️ ELIMINAR TODO</button>";
    echo "<button type='button' class='btn btn-cancel' onclick='window.location.href=\"admin/dashboard.php\"'>❌ Cancelar</button>";
    echo "</form>";
    echo "</div>";
}

echo "<div class='section info'>";
echo "<h2>🔗 Enlaces de Administración</h2>";
echo "<p>";
echo "<a href='admin/dashboard.php' style='background: #2196F3; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>🏠 Dashboard</a>";
echo "<a href='admin/rehabilitaciones.php' style='background: #4CAF50; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>📊 Rehabilitaciones</a>";
echo "<a href='verificar_rehabilitaciones_finales.php' style='background: #ff9800; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>📈 Verificar Sistema</a>";
echo "</p>";
echo "</div>";

echo "</div>";

echo "<script>
function confirmarEliminacion() {
    const confirmText = 'SI_ELIMINAR_TODO';
    const userInput = document.querySelector('input[name=\"confirmar_limpieza\"]').value;
    
    if (userInput !== confirmText) {
        alert('Debe escribir exactamente: ' + confirmText);
        return false;
    }
    
    return confirm('¿Está ABSOLUTAMENTE SEGURO de que desea eliminar TODOS los participantes y sus datos?\\n\\nEsta acción NO se puede deshacer.');
}
</script>";
?>
