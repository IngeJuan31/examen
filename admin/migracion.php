<?php
// filepath: c:\xampp\htdocs\examen_ingreso\admin\migracion_foreign_keys.php

require_once '../config/db.php';

try {
    echo "<h2>🔧 Reparando llaves foráneas...</h2>";
    
    // 1. Verificar si existe la tabla tipo_identificacion
    $stmt = $pdo->query("SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name = 'tipo_identificacion'
    )");
    
    $tabla_existe = $stmt->fetchColumn();
    
    if (!$tabla_existe) {
        echo "<p>✅ Creando tabla tipo_identificacion...</p>";
        
        $pdo->exec("
            CREATE TABLE tipo_identificacion (
                id_tipo SERIAL PRIMARY KEY,
                codigo VARCHAR(5) NOT NULL UNIQUE,
                nombre VARCHAR(50) NOT NULL,
                descripcion TEXT,
                estado BOOLEAN DEFAULT TRUE,
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Insertar tipos básicos
        $tipos = [
            ['CC', 'Cédula de Ciudadanía', 'Cédula de Ciudadanía'],
            ['TI', 'Tarjeta de Identidad', 'Tarjeta de Identidad'],
            ['CE', 'Cédula de Extranjería', 'Cédula de Extranjería'],
            ['PAS', 'Pasaporte', 'Pasaporte'],
            ['NIT', 'NIT', 'Número de Identificación Tributaria']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO tipo_identificacion (codigo, nombre, descripcion) VALUES (?, ?, ?)");
        foreach ($tipos as $tipo) {
            $stmt->execute($tipo);
        }
        
        echo "<p>✅ Tipos de identificación creados.</p>";
    } else {
        echo "<p>ℹ️ Tabla tipo_identificacion ya existe.</p>";
    }
    
    // 2. Verificar si existe la columna id_tipo_identificacion en participantes
    $stmt = $pdo->query("SELECT EXISTS (
        SELECT FROM information_schema.columns 
        WHERE table_name = 'participantes' 
        AND column_name = 'id_tipo_identificacion'
        AND table_schema = 'public'
    )");
    
    $columna_existe = $stmt->fetchColumn();
    
    if ($columna_existe) {
        echo "<p>✅ Columna id_tipo_identificacion existe en participantes.</p>";
        
        // 3. Eliminar foreign key problemática (la que está NOT VALID)
        echo "<p>🧹 Eliminando foreign key problemática...</p>";
        $pdo->exec("ALTER TABLE participantes DROP CONSTRAINT IF EXISTS participantes_tipo_identificacion_fk");
        
        // 4. Verificar si la FK correcta ya existe
        $stmt = $pdo->query("SELECT EXISTS (
            SELECT FROM information_schema.table_constraints 
            WHERE constraint_name = 'participantes_id_tipo_identificacion_fkey'
            AND table_name = 'participantes'
            AND table_schema = 'public'
        )");
        
        $fk_correcta_existe = $stmt->fetchColumn();
        
        if ($fk_correcta_existe) {
            echo "<p>✅ La foreign key correcta ya existe.</p>";
        } else {
            echo "<p>🔗 Creando foreign key correcta...</p>";
            
            // 5. Actualizar registros con id_tipo_identificacion NULL
            $pdo->exec("
                UPDATE participantes 
                SET id_tipo_identificacion = (SELECT id_tipo FROM tipo_identificacion WHERE codigo = 'CC' LIMIT 1)
                WHERE id_tipo_identificacion IS NULL
            ");
            
            // 6. Crear la foreign key correcta
            $pdo->exec("
                ALTER TABLE participantes 
                ADD CONSTRAINT participantes_id_tipo_identificacion_fkey 
                FOREIGN KEY (id_tipo_identificacion) 
                REFERENCES tipo_identificacion(id_tipo)
                ON DELETE SET NULL
                ON UPDATE CASCADE
            ");
            
            echo "<p>✅ Foreign key correcta creada.</p>";
        }
        
    } else {
        // Si no existe la columna, crearla
        echo "<p>🔧 Creando columna id_tipo_identificacion...</p>";
        
        $pdo->exec("ALTER TABLE participantes ADD COLUMN id_tipo_identificacion INTEGER");
        
        // Asignar tipo por defecto (CC)
        $pdo->exec("
            UPDATE participantes 
            SET id_tipo_identificacion = (SELECT id_tipo FROM tipo_identificacion WHERE codigo = 'CC' LIMIT 1)
        ");
        
        // Crear foreign key
        $pdo->exec("
            ALTER TABLE participantes 
            ADD CONSTRAINT participantes_id_tipo_identificacion_fkey 
            FOREIGN KEY (id_tipo_identificacion) 
            REFERENCES tipo_identificacion(id_tipo)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");
        
        echo "<p>✅ Columna y foreign key creadas correctamente.</p>";
    }
    
    // 7. Verificar integridad final
    $stmt = $pdo->query("
        SELECT COUNT(*) as total_participantes,
               COUNT(id_tipo_identificacion) as con_tipo,
               COUNT(*) - COUNT(id_tipo_identificacion) as sin_tipo
        FROM participantes
    ");
    
    $integridad = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<div style='background: #f0f8ff; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
    echo "<h4>📊 Verificación de integridad:</h4>";
    echo "<ul>";
    echo "<li>✅ Total participantes: {$integridad['total_participantes']}</li>";
    echo "<li>✅ Con tipo de identificación: {$integridad['con_tipo']}</li>";
    echo "<li>" . ($integridad['sin_tipo'] > 0 ? "⚠️" : "✅") . " Sin tipo de identificación: {$integridad['sin_tipo']}</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<h3>🎉 ¡Migración completada exitosamente!</h3>";
    echo "<p><a href='participantes.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>← Volver a participantes</a></p>";
    
} catch (Exception $e) {
    echo "<h3>❌ Error durante la migración:</h3>";
    echo "<div style='background: #ffe6e6; padding: 15px; border-radius: 8px; border-left: 4px solid #dc3545;'>";
    echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>Archivo:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Línea:</strong> " . $e->getLine() . "</p>";
    echo "</div>";
    echo "<p><a href='participantes.php' style='background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>← Volver a participantes</a></p>";
}
?>
 <?php require_once '../includes/footer.php'; ?>