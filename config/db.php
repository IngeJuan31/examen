<?php
// filepath: c:\xampp\htdocs\examen_ingreso\config\db.php
$host = '192.168.1.29';
$db = 'examen_ingreso';
$user = 'postgres';
$pass = '123456';

try {
    // CORREGIDO: Sin charset en DSN para PostgreSQL
    $pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass);
    
    // Configurar atributos PDO
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
    // OPCIONAL: Configurar encoding después de la conexión
    $pdo->exec("SET NAMES 'utf8'");
    
    error_log("✅ Conexión a PostgreSQL establecida exitosamente");
    
} catch (PDOException $e) {
    error_log("❌ Error de conexión a PostgreSQL: " . $e->getMessage());
    die("Error de conexión: " . $e->getMessage());
}
?>

