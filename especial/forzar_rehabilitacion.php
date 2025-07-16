<?php
session_start();
require_once 'config/db.php';

// Verificar si está logueado
if (!isset($_SESSION['participante_id'])) {
    echo "No hay sesión activa. Por favor inicia sesión.";
    exit;
}

$participante_id = $_SESSION['participante_id'];
$participante_nombre = $_SESSION['participante_nombre'];

echo "<h2>Forzar Creación de Rehabilitaciones Automáticas</h2>";
echo "<h3>Participante: $participante_nombre (ID: $participante_id)</h3>";

try {
    // 1. Calcular resultado actual
    require_once 'controllers/calcular_resultado.php';
    echo "<h4>1. Recalculando resultado actual...</h4>";
    
    $resultado_calculo = calcularYGuardarResultado($participante_id, $pdo);
    
    if ($resultado_calculo['status'] === 'success') {
        $data = $resultado_calculo['data'];
        echo "<p style='color: green;'>✅ Resultado calculado exitosamente</p>";
        echo "<p><strong>Total preguntas:</strong> {$data['total_preguntas']}</p>";
        echo "<p><strong>Respuestas correctas:</strong> {$data['respuestas_correctas']}</p>";
        echo "<p><strong>Porcentaje general:</strong> {$data['porcentaje']}%</p>";
        echo "<p><strong>Estado:</strong> {$data['estado']}</p>";
        
        echo "<h4>Competencias:</h4>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Competencia</th><th>Correctas</th><th>Total</th><th>Porcentaje</th><th>Estado</th></tr>";
        
        $competencias_reprobadas = [];
        foreach ($data['competencias'] as $comp) {
            $color = $comp['porcentaje'] >= 70 ? 'green' : 'red';
            echo "<tr style='color: $color;'>";
            echo "<td>{$comp['competencia']}</td>";
            echo "<td>{$comp['correctas']}</td>";
            echo "<td>{$comp['total']}</td>";
            echo "<td>{$comp['porcentaje']}%</td>";
            echo "<td>" . ($comp['porcentaje'] >= 70 ? 'APROBADA' : 'REPROBADA') . "</td>";
            echo "</tr>";
            
            if ($comp['porcentaje'] < 70) {
                $competencias_reprobadas[] = $comp['competencia'];
            }
        }
        echo "</table>";
        
        if (!empty($competencias_reprobadas)) {
            echo "<p style='color: red;'><strong>Competencias reprobadas:</strong> " . implode(', ', $competencias_reprobadas) . "</p>";
        } else {
            echo "<p style='color: green;'><strong>Todas las competencias están aprobadas</strong></p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Error al calcular resultado: {$resultado_calculo['message']}</p>";
    }
    
    // 2. Verificar rehabilitaciones actuales
    echo "<h4>2. Verificando rehabilitaciones después del cálculo...</h4>";
    
    $stmt_rehab = $pdo->prepare("
        SELECT * 
        FROM rehabilitaciones_competencia 
        WHERE participante_id = ? 
        ORDER BY estado DESC, fecha_rehabilitacion DESC
    ");
    $stmt_rehab->execute([$participante_id]);
    $rehabilitaciones = $stmt_rehab->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rehabilitaciones)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Competencia</th><th>Admin</th><th>Estado</th><th>Motivo</th><th>Fecha</th></tr>";
        foreach ($rehabilitaciones as $rehab) {
            $color = $rehab['estado'] === 'ACTIVA' ? 'green' : 'gray';
            echo "<tr style='color: $color;'>";
            echo "<td>{$rehab['competencia_nombre']}</td>";
            echo "<td>{$rehab['admin_nombre']}</td>";
            echo "<td><strong>{$rehab['estado']}</strong></td>";
            echo "<td>{$rehab['motivo']}</td>";
            echo "<td>{$rehab['fecha_rehabilitacion']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        $activas = array_filter($rehabilitaciones, function($r) { return $r['estado'] === 'ACTIVA'; });
        echo "<p><strong>Rehabilitaciones activas:</strong> " . count($activas) . "</p>";
        
    } else {
        echo "<p style='color: orange;'>No hay rehabilitaciones registradas.</p>";
    }
    
    // 3. Verificar estado del participante
    echo "<h4>3. Verificando estado del participante...</h4>";
    
    $stmt_participante = $pdo->prepare("SELECT * FROM participantes WHERE id_participante = ?");
    $stmt_participante->execute([$participante_id]);
    $participante_info = $stmt_participante->fetch(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Estado actual del examen:</strong> {$participante_info['estado_examen']}</p>";
    
    // 4. Actualizar estado si es necesario
    if ($participante_info['estado_examen'] === 'COMPLETADO' && !empty($competencias_reprobadas)) {
        echo "<h4>4. Actualizando estado del participante...</h4>";
        
        $stmt_update = $pdo->prepare("UPDATE participantes SET estado_examen = 'REHABILITADO' WHERE id_participante = ?");
        if ($stmt_update->execute([$participante_id])) {
            echo "<p style='color: green;'>✅ Estado actualizado a REHABILITADO</p>";
        } else {
            echo "<p style='color: red;'>❌ Error al actualizar estado</p>";
        }
    }
    
    // 5. Verificar si ahora se puede hacer la redirección
    echo "<h4>5. Verificación final de redirección...</h4>";
    
    $stmt_rehab_final = $pdo->prepare("
        SELECT COUNT(*) as rehabilitaciones_activas 
        FROM rehabilitaciones_competencia 
        WHERE participante_id = ? AND estado = 'ACTIVA'
    ");
    $stmt_rehab_final->execute([$participante_id]);
    $tiene_rehabilitaciones = $stmt_rehab_final->fetchColumn() > 0;
    
    if (!empty($competencias_reprobadas) && $tiene_rehabilitaciones) {
        echo "<p style='color: green; font-weight: bold;'>🚀 LISTO PARA REDIRECCIÓN AUTOMÁTICA</p>";
        echo "<p><a href='examen.php?auto_rehab=1' style='background: #4CAF50; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px;'>Ir a Rehabilitación</a></p>";
    } else if (!empty($competencias_reprobadas)) {
        echo "<p style='color: red;'>❌ Hay competencias reprobadas pero no se crearon rehabilitaciones automáticas</p>";
    } else {
        echo "<p style='color: blue;'>ℹ️ No necesita rehabilitación - todas las competencias aprobadas</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr>";
echo "<p><a href='ver_resultado.php?no_redirect=1'>← Volver a Ver Resultado</a></p>";
?>
