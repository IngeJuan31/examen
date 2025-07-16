<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/db.php';

// Verifica que hay sesión activa
if (!isset($_SESSION['id_admin'])) {
    echo json_encode(['status' => 'error', 'msg' => 'No logueado']);
    exit;
}

// Recargar permisos desde la base de datos
try {
    $stmt = $pdo->prepare("
        SELECT p.nombre_permiso 
        FROM permisos p
        JOIN usuarios_permisos up ON p.id_permiso = up.id_permiso
        WHERE up.id_admin = :id_admin
    ");
    $stmt->execute([':id_admin' => $_SESSION['id_admin']]);
    $permisos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $_SESSION['permisos'] = $permisos;

    
} catch (Exception $e) {
    
}
