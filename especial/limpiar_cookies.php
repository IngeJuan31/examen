<?php
// Limpiar todas las cookies y sesiones
session_start();

// Destruir sesión
session_destroy();

// Limpiar cookies si existen
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Limpiar cualquier otra cookie del dominio
$cookies = ['PHPSESSID', 'participante_id', 'admin_id'];
foreach ($cookies as $cookie) {
    if (isset($_COOKIE[$cookie])) {
        setcookie($cookie, '', time()-3600, '/');
        unset($_COOKIE[$cookie]);
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cookies Limpiadas - INCATEC</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
        }
        .success-icon {
            color: #4CAF50;
            font-size: 48px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .btn {
            background: #4CAF50;
            color: white;
            padding: 12px 25px;
            text-decoration: none;
            border-radius: 6px;
            display: inline-block;
            margin: 10px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #45a049;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✅</div>
        <h1>Cookies y Sesión Limpiadas</h1>
        <p>Se han eliminado todas las cookies y datos de sesión.</p>
        <p>El problema de redirecciones infinitas ha sido solucionado.</p>
        
        <div style="margin-top: 25px;">
            <a href="index.php" class="btn">
                <i class="fas fa-home"></i> Ir al Inicio
            </a>
            <a href="login_prueba.php" class="btn" style="background: #2196F3;">
                <i class="fas fa-sign-in-alt"></i> Login de Prueba
            </a>
        </div>
    </div>

    <script>
        // Limpiar localStorage y sessionStorage
        localStorage.clear();
        sessionStorage.clear();
        
        // Mostrar confirmación
        Swal.fire({
            title: '¡Limpieza Completa!',
            text: 'Todas las cookies y datos de sesión han sido eliminados.',
            icon: 'success',
            timer: 3000,
            showConfirmButton: false
        });
    </script>
</body>
</html>
