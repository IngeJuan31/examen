<?php
require_once 'config/db.php';

try {
    echo "🧪 Creando escenario de prueba para rehabilitación por competencia...\n\n";
    
    // 1. Crear un participante de prueba
    $stmt = $pdo->prepare("
        INSERT INTO participantes (nombre, correo, identificacion, usuario, clave, estado_examen) 
        VALUES (?, ?, ?, ?, ?, ?) 
        ON CONFLICT (usuario) DO UPDATE SET 
            estado_examen = EXCLUDED.estado_examen
        RETURNING id_participante
    ");
    $stmt->execute([
        'Usuario de Prueba Rehabilitación',
        'test_rehab@ejemplo.com',
        '12345678', 
        'test_rehab', 
        password_hash('123456', PASSWORD_DEFAULT),
        'REHABILITADO'
    ]);
    
    $participante_id = $stmt->fetchColumn();
    if (!$participante_id) {
        // Si no se insertó, obtener el ID existente
        $stmt = $pdo->prepare("SELECT id_participante FROM participantes WHERE usuario = ?");
        $stmt->execute(['test_rehab']);
        $participante_id = $stmt->fetchColumn();
    }
    
    echo "✅ Participante creado/actualizado - ID: $participante_id\n";
    
    // 2. Asignar nivel de examen
    $stmt = $pdo->prepare("
        INSERT INTO asignaciones_examen (id_participante, nivel_dificultad, fecha_asignacion)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT (id_participante) DO UPDATE SET
            nivel_dificultad = EXCLUDED.nivel_dificultad,
            fecha_asignacion = EXCLUDED.fecha_asignacion
    ");
    $stmt->execute([$participante_id, 'medio']);
    echo "✅ Nivel de examen asignado: medio\n";
    
    // 3. Obtener una competencia para simular rehabilitación
    $stmt = $pdo->query("SELECT id_competencia, nombre FROM competencias ORDER BY id_competencia LIMIT 1");
    $competencia = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$competencia) {
        throw new Exception("No hay competencias disponibles. Crear una competencia primero.");
    }
    
    echo "✅ Competencia seleccionada: {$competencia['nombre']} (ID: {$competencia['id_competencia']})\n";
    
    // 4. Crear rehabilitación por competencia específica
    $stmt = $pdo->prepare("
        DELETE FROM rehabilitaciones_competencia 
        WHERE participante_id = ? AND competencia_id = ?
    ");
    $stmt->execute([$participante_id, $competencia['id_competencia']]);
    
    $stmt = $pdo->prepare("
        INSERT INTO rehabilitaciones_competencia (
            participante_id, competencia_id, competencia_nombre, 
            admin_nombre, intento_anterior, motivo, estado
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $participante_id,
        $competencia['id_competencia'],
        $competencia['nombre'],
        'Admin de Prueba',
        0,
        'Rehabilitación de prueba para testing del sistema',
        'ACTIVA'
    ]);
    echo "✅ Rehabilitación por competencia creada\n";
    
    // 5. Crear historial de examen anterior (simulado)
    $stmt = $pdo->prepare("
        DELETE FROM historial_examenes WHERE participante_id = ? AND intento_numero = 1
    ");
    $stmt->execute([$participante_id]);
    
    $stmt = $pdo->prepare("
        INSERT INTO historial_examenes (
            participante_id, intento_numero, total_preguntas, respuestas_correctas,
            respuestas_incorrectas, porcentaje, nivel_examen, tiempo_total_segundos,
            estado, fecha_realizacion
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $participante_id, 1, 10, 5, 5, 50.0, 'medio', 600, 'REPROBADO', 
        date('Y-m-d H:i:s', strtotime('-1 hour'))
    ]);
    
    // Obtener el ID del historial
    $stmt = $pdo->prepare("SELECT id_historial FROM historial_examenes WHERE participante_id = ? AND intento_numero = 1");
    $stmt->execute([$participante_id]);
    $historial_id = $stmt->fetchColumn();
    
    if ($historial_id) {
        // 6. Crear historial por competencia (simulando que reprobó esta competencia)
        $stmt = $pdo->prepare("
            DELETE FROM historial_competencias 
            WHERE historial_id = ? AND competencia_id = ?
        ");
        $stmt->execute([$historial_id, $competencia['id_competencia']]);
        
        $stmt = $pdo->prepare("
            INSERT INTO historial_competencias (
                historial_id, competencia_id, competencia_nombre, 
                total_preguntas, respuestas_correctas, porcentaje, estado
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $historial_id,
            $competencia['id_competencia'],
            $competencia['nombre'],
            5, 2, 40.0, 'REPROBADO'
        ]);
        echo "✅ Historial por competencia creado\n";
    }
    
    echo "\n🎉 ¡Escenario de prueba creado exitosamente!\n\n";
    echo "📋 DATOS DE ACCESO PARA PRUEBA:\n";
    echo "   Usuario: test_rehab\n";
    echo "   Contraseña: 123456\n";
    echo "   Estado: REHABILITADO\n";
    echo "   Competencia a rehabilitar: {$competencia['nombre']}\n\n";
    echo "🔗 Ahora puedes acceder a: http://localhost/examen_ingreso/\n";
    echo "   Y usar las credenciales de arriba para probar el sistema.\n\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
