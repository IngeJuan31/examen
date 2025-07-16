<?php
// Test de conexión básico
echo "Probando conexión a base de datos...\n";

try {
    $pdo = new PDO("pgsql:host=192.168.1.29;dbname=examen_ingreso", 'postgres', '123456');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Conexión exitosa\n";
    
    // Listar todas las tablas
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Tablas encontradas: " . count($tables) . "\n";
    foreach ($tables as $table) {
        echo "- $table\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
