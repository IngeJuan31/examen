<?php
$pdo = new PDO('pgsql:host=192.168.1.29;dbname=examen_ingreso', 'postgres', '123456');
$stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'participantes' ORDER BY ordinal_position");
$columns = $stmt->fetchAll();
echo "Estructura de tabla participantes:\n";
foreach ($columns as $col) {
    echo "- " . $col['column_name'] . ' (' . $col['data_type'] . ')' . "\n";
}
?>
