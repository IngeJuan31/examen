<?php
// Configuración directa de conexión
$host = '192.168.1.29';
$db = 'examen_ingreso';
$user = 'postgres';
$pass = '123456';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

echo "Limpiando datos de prueba existentes...\n";

try {
    // Limpiar en orden correcto (por claves foráneas)
    $pdo->exec("DELETE FROM rehabilitaciones_competencia WHERE participante_id IN (SELECT id_participante FROM participantes WHERE identificacion IN ('12345678', '87654321', '11223344', '44332211'))");
    $pdo->exec("DELETE FROM historial_competencias WHERE historial_id IN (SELECT id_historial FROM historial_examenes WHERE participante_id IN (SELECT id_participante FROM participantes WHERE identificacion IN ('12345678', '87654321', '11223344', '44332211')))");
    $pdo->exec("DELETE FROM resultados WHERE participante_id IN (SELECT id_participante FROM participantes WHERE identificacion IN ('12345678', '87654321', '11223344', '44332211'))");
    $pdo->exec("DELETE FROM historial_examenes WHERE participante_id IN (SELECT id_participante FROM participantes WHERE identificacion IN ('12345678', '87654321', '11223344', '44332211'))");
    $pdo->exec("DELETE FROM participantes WHERE identificacion IN ('12345678', '87654321', '11223344', '44332211')");
    
    echo "✓ Datos limpiados exitosamente\n";
    echo "Ahora ejecuta nuevamente el script de inserción.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
