<?php
session_start();

// Elimina todas las variables de sesión
session_unset();

// Destruye la sesión completamente
session_destroy();

// Redirige al login
header("Location: /examen_ingreso/admin/login.php");
exit;
?>
// Fin del archivo logout_admin.php