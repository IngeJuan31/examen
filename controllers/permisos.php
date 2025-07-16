<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function verificarPermiso($permiso_requerido) {
    if (!isset($_SESSION['id_admin'])) {
        header("Location: ../login.php");
        exit;
    }

    if (empty($_SESSION['permisos']) || !in_array($permiso_requerido, $_SESSION['permisos'])) {
        header("Location: ../acceso_denegado.php");
        exit;
    }
}
    