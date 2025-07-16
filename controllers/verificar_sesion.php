<?php
// Iniciar sesión si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar login
if (!isset($_SESSION['id_admin'])) {
    header("Location: /examen_ingreso/admin/login.php");
    exit;
}

// Función global para validar permisos
function tienePermiso($permiso) {
    return in_array($permiso, $_SESSION['permisos'] ?? []);
}
