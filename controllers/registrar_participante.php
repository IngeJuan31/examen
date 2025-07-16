<?php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $_POST['nombre'] ?? '';
    $correo = $_POST['correo'] ?? '';

    try {
        $stmt = $pdo->prepare("INSERT INTO participantes (nombre, correo) VALUES (?, ?) RETURNING id_participante");
        $stmt->execute([$nombre, $correo]);
        $id = $stmt->fetchColumn();
        echo json_encode(['status' => 'ok', 'id_participante' => $id]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Método HTTP no permitido']);
}