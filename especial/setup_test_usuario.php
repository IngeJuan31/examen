<?php
session_start();
require_once 'config/db.php';

echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .header { background: linear-gradient(135deg, #2196F3, #1976d2); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
    .test-btn { background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px; display: inline-block; }
    .test-btn:hover { background: #388e3c; }
    .results { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #2196F3; }
</style>";

echo "<div class='container'>";
echo "<div class='header'><h1>🧪 Test Completo: Flujo de Aprobación</h1></div>";

// Limpiar datos de prueba anteriores
try {
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM respuestas WHERE id_participante IN (SELECT id_participante FROM participantes WHERE usuario = 'test_usuario')");
    $pdo->exec("DELETE FROM resultado_competencias WHERE resultado_id IN (SELECT id_resultado FROM resultados WHERE participante_id IN (SELECT id_participante FROM participantes WHERE usuario = 'test_usuario'))");
    $pdo->exec("DELETE FROM resultados WHERE participante_id IN (SELECT id_participante FROM participantes WHERE usuario = 'test_usuario')");
    $pdo->exec("DELETE FROM historial_competencias WHERE historial_id IN (SELECT id_historial FROM historial_examenes WHERE participante_id IN (SELECT id_participante FROM participantes WHERE usuario = 'test_usuario'))");
    $pdo->exec("DELETE FROM historial_examenes WHERE participante_id IN (SELECT id_participante FROM participantes WHERE usuario = 'test_usuario')");
    $pdo->exec("DELETE FROM rehabilitaciones_competencia WHERE participante_id IN (SELECT id_participante FROM participantes WHERE usuario = 'test_usuario')");
    $pdo->exec("DELETE FROM rehabilitaciones WHERE participante_id IN (SELECT id_participante FROM participantes WHERE usuario = 'test_usuario')");
    $pdo->exec("DELETE FROM asignaciones_examen WHERE id_participante IN (SELECT id_participante FROM participantes WHERE usuario = 'test_usuario')");
    $pdo->exec("DELETE FROM participantes WHERE usuario = 'test_usuario'");
    $pdo->commit();
    
    echo "<div class='results'>✅ Datos de prueba limpiados</div>";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div class='results' style='border-left-color: #f44336;'>❌ Error limpiando datos: " . $e->getMessage() . "</div>";
}

// Crear participante de prueba
try {
    $stmt = $pdo->prepare("INSERT INTO participantes (nombre, correo, identificacion, usuario, clave, estado_examen, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) RETURNING id_participante");
    $clave_hash = password_hash('123456', PASSWORD_DEFAULT);
    $stmt->execute(['USUARIO_PRUEBA', 'test@example.com', '12345678', 'test_usuario', $clave_hash, 'ACTIVO']);
    $test_participante_id = $stmt->fetchColumn();
    
    // Asignar nivel de examen
    $stmt = $pdo->prepare("INSERT INTO asignaciones_examen (id_participante, nivel_dificultad, fecha_asignacion) VALUES (?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute([$test_participante_id, 'bajo']);
    
    echo "<div class='results'>✅ Participante de prueba creado (ID: $test_participante_id)</div>";
    
    // Configurar sesión
    $_SESSION['participante_id'] = $test_participante_id;
    $_SESSION['participante_nombre'] = 'USUARIO_PRUEBA';
    
} catch (Exception $e) {
    echo "<div class='results' style='border-left-color: #f44336;'>❌ Error creando participante: " . $e->getMessage() . "</div>";
}

// Obtener preguntas para simular respuestas
try {
    $stmt = $pdo->prepare("
        SELECT p.id_pregunta, p.texto_pregunta, c.nombre as competencia, c.id_competencia
        FROM preguntas p
        JOIN competencias c ON p.id_competencia = c.id_competencia
        WHERE p.nivel_dificultad = 'bajo' OR p.nivel_dificultad IS NULL
        ORDER BY c.nombre, p.id_pregunta
        LIMIT 10
    ");
    $stmt->execute();
    $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='results'>";
    echo "<h3>📝 Preguntas Disponibles para Simular</h3>";
    echo "<p>Total de preguntas encontradas: " . count($preguntas) . "</p>";
    
    $competencias_encontradas = array_unique(array_column($preguntas, 'competencia'));
    echo "<p>Competencias: " . implode(', ', $competencias_encontradas) . "</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='results' style='border-left-color: #f44336;'>❌ Error obteniendo preguntas: " . $e->getMessage() . "</div>";
}

echo "<h3>🎯 Acciones de Prueba</h3>";
echo "<a href='simular_examen_reprobado.php' class='test-btn'>📝 Simular Examen Reprobado</a>";
echo "<a href='ver_resultado.php' class='test-btn'>📊 Ver Resultados</a>";
echo "<a href='examen.php' class='test-btn'>✏️ Tomar Examen</a>";
echo "<a href='index.php' class='test-btn' style='background: #f44336;'>🏠 Logout</a>";

echo "</div>";
?>
