<?php
require_once __DIR__ . '/../config/db.php';

$id_competencia = $_GET['id_competencia'] ?? null;
$limite = $_GET['limite'] ?? 5;

if ($id_competencia) {
    $stmt = $pdo->prepare("
        SELECT p.id_pregunta, p.enunciado, o.id_opcion, o.texto
        FROM preguntas p
        JOIN opciones o ON p.id_pregunta = o.id_pregunta
        WHERE p.id_competencia = ?
        ORDER BY RANDOM()
        LIMIT ?
    ");
    $stmt->execute([$id_competencia, $limite]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} else {
    echo json_encode(['status' => 'error', 'message' => 'Falta id_competencia']);
}
