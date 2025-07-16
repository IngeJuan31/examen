<?php
// Configuración directa de conexión
$host = '192.168.1.29';
$db = 'examen_ingreso';
$user = 'postgres';
$pass = '123456';

echo "Configurando base de datos del sistema de exámenes...\n";

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Conexión a base de datos establecida\n";
    // Crear tabla participantes (si no existe)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS participantes (
            id_participante SERIAL PRIMARY KEY,
            nombre VARCHAR(255) NOT NULL,
            identificacion VARCHAR(50) UNIQUE NOT NULL,
            usuario VARCHAR(100) UNIQUE NOT NULL,
            telefono VARCHAR(20),
            email VARCHAR(255),
            nivel_examen VARCHAR(50) DEFAULT 'BASICO',
            estado_examen VARCHAR(50) DEFAULT 'PENDIENTE',
            fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ Tabla participantes creada/verificada\n";

    // Crear tabla competencias (si no existe)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS competencias (
            id_competencia SERIAL PRIMARY KEY,
            nombre VARCHAR(255) NOT NULL,
            descripcion TEXT,
            peso_evaluacion DECIMAL(5,2) DEFAULT 25.00,
            fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ Tabla competencias creada/verificada\n";

    // Crear tabla historial_examenes (si no existe)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS historial_examenes (
            id_historial SERIAL PRIMARY KEY,
            participante_id INTEGER REFERENCES participantes(id_participante),
            intento_numero INTEGER NOT NULL DEFAULT 1,
            total_preguntas INTEGER NOT NULL DEFAULT 0,
            respuestas_correctas INTEGER NOT NULL DEFAULT 0,
            respuestas_incorrectas INTEGER NOT NULL DEFAULT 0,
            porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0,
            estado VARCHAR(20) NOT NULL DEFAULT 'COMPLETADO',
            fecha_realizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ Tabla historial_examenes creada/verificada\n";

    // Crear tabla historial_competencias (si no existe)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS historial_competencias (
            id_historial_competencia SERIAL PRIMARY KEY,
            historial_id INTEGER REFERENCES historial_examenes(id_historial),
            competencia_id INTEGER REFERENCES competencias(id_competencia),
            competencia_nombre VARCHAR(255) NOT NULL,
            total_preguntas INTEGER NOT NULL DEFAULT 0,
            respuestas_correctas INTEGER NOT NULL DEFAULT 0,
            porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0,
            estado VARCHAR(20) NOT NULL DEFAULT 'COMPLETADO',
            fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ Tabla historial_competencias creada/verificada\n";

    // Crear tabla rehabilitaciones_competencia (si no existe)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rehabilitaciones_competencia (
            id_rehabilitacion_competencia SERIAL PRIMARY KEY,
            participante_id INTEGER REFERENCES participantes(id_participante),
            competencia_id INTEGER REFERENCES competencias(id_competencia),
            competencia_nombre VARCHAR(255) NOT NULL,
            admin_id INTEGER DEFAULT 1,
            admin_nombre VARCHAR(100) NOT NULL,
            intento_anterior INTEGER NOT NULL,
            motivo TEXT NOT NULL,
            estado VARCHAR(20) DEFAULT 'ACTIVA',
            fecha_rehabilitacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_utilizacion TIMESTAMP NULL
        )
    ");
    echo "✓ Tabla rehabilitaciones_competencia creada/verificada\n";

    // Crear tabla resultados (si no existe)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resultados (
            id_resultado SERIAL PRIMARY KEY,
            participante_id INTEGER REFERENCES participantes(id_participante),
            porcentaje DECIMAL(5,2) NOT NULL,
            fecha_examen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_realizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ Tabla resultados creada/verificada\n";

    // Crear tabla asignaciones_examen (si no existe)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asignaciones_examen (
            id_asignacion SERIAL PRIMARY KEY,
            id_participante INTEGER REFERENCES participantes(id_participante),
            nivel_dificultad VARCHAR(50) DEFAULT 'BASICO',
            fecha_asignacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ Tabla asignaciones_examen creada/verificada\n";

    // Insertar competencias básicas si no existen
    $stmt_check = $pdo->query("SELECT COUNT(*) as count FROM competencias");
    $count = $stmt_check->fetch()['count'];
    
    if ($count == 0) {
        $competencias = [
            ['Competencia Técnica', 'Conocimientos técnicos específicos del área'],
            ['Competencia Comunicativa', 'Habilidades de comunicación y expresión'],
            ['Competencia Analítica', 'Capacidad de análisis y resolución de problemas'],
            ['Competencia de Gestión', 'Habilidades de gestión y liderazgo']
        ];
        
        foreach ($competencias as $comp) {
            $pdo->prepare("INSERT INTO competencias (nombre, descripcion) VALUES (?, ?)")->execute($comp);
        }
        echo "✓ Competencias básicas insertadas\n";
    }

    // Crear índices para optimizar consultas
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historial_competencias_historial_id ON historial_competencias(historial_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historial_competencias_competencia_id ON historial_competencias(competencia_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rehabilitaciones_competencia_participante ON rehabilitaciones_competencia(participante_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rehabilitaciones_competencia_competencia ON rehabilitaciones_competencia(competencia_id)");
    echo "✓ Índices creados/verificados\n";

    echo "\n✅ Base de datos configurada exitosamente!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
