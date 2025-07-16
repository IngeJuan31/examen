<?php
require_once 'config/db.php';

$stmt = $pdo->prepare('UPDATE asignaciones_examen SET nivel_dificultad = ? WHERE id_participante = ?');
$stmt->execute(['bajo', 40]);
echo "Nivel actualizado a: bajo\n";

// Verificar cambio
$stmt = $pdo->prepare('SELECT nivel_dificultad FROM asignaciones_examen WHERE id_participante = ?');
$stmt->execute([40]);
$nivel = $stmt->fetchColumn();
echo "Nivel verificado: $nivel\n";
?>
