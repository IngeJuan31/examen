<?php
require_once __DIR__ . '/../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);

foreach ($data as $respuesta) {
    $id_participante = $respuesta['id_participante'];
    $id_pregunta = $respuesta['id_pregunta'];
    $id_opcion = $respuesta['id_opcion'];

    $stmt = $pdo->prepare("SELECT es_correcta FROM opciones WHERE id_opcion = ?");
    $stmt->execute([$id_opcion]);
    $es_correcta = $stmt->fetchColumn();

    $insert = $pdo->prepare("
        INSERT INTO respuestas (id_participante, id_pregunta, id_opcion, es_correcta)
        VALUES (?, ?, ?, ?)
    ");
    $insert->execute([$id_participante, $id_pregunta, $id_opcion, $es_correcta]);
}

echo json_encode(['status' => 'ok']);
