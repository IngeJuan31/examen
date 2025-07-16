<?php
session_start();
require_once '../config/db.php';

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario']);
    $clave = trim($_POST['clave']);

    if ($usuario && $clave) {
        $sql = "SELECT * FROM usuarios_admin WHERE usuario = :usuario AND estado = true";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':usuario' => $usuario]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($clave, $admin['clave'])) {
            // ✅ Guardar info básica
            $_SESSION['id_admin'] = $admin['id_admin'];
            $_SESSION['nombre_admin'] = $admin['nombre'];
            $_SESSION['rol'] = $admin['id_rol'];

            // ✅ Cargar permisos asociados al usuario
            $permQuery = "
                SELECT p.nombre_permiso 
                FROM permisos p
                JOIN usuarios_permisos up ON up.id_permiso = p.id_permiso
                WHERE up.id_admin = :id_admin
            ";
            $stmtPerm = $pdo->prepare($permQuery);
            $stmtPerm->execute([':id_admin' => $admin['id_admin']]);
            $permisos = $stmtPerm->fetchAll(PDO::FETCH_COLUMN);

            // Guardar permisos en sesión
            $_SESSION['permisos'] = $permisos;

            // 🔁 Redirigir al panel
            header("Location: /examen_ingreso/admin/index.php");
            exit;
        } else {
            $mensaje = "❌ Usuario o contraseña incorrectos.";
        }
    } else {
        $mensaje = "⚠️ Por favor, completa todos los campos.";
    }
}
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INCATEC - Panel Administrativo</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #003f91 0%, #0056b3 50%, #667eea 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Elementos decorativos de fondo */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 20s linear infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            100% { transform: translateY(-20px) rotate(360deg); }
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
            position: relative;
            z-index: 1;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .login-header {
            background: linear-gradient(135deg, #003f91, #0056b3);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="10" cy="50" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="90" cy="30" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .logo-container {
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }
        
        .logo-container img {
            width: 80px;
            height: 80px;
            object-fit: contain;
            filter: brightness(0) invert(1);
            margin-bottom: 10px;
        }
        
        .login-header h1 {
            font-size: 2rem;
            margin-bottom: 8px;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }
        
        .login-header p {
            opacity: 0.9;
            font-size: 1rem;
            font-weight: 400;
            position: relative;
            z-index: 1;
        }
        
        .login-form {
            padding: 40px 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
            transition: color 0.3s ease;
        }
        
        .form-group label i {
            margin-right: 8px;
            color: #003f91;
            width: 16px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .form-input {
            width: 100%;
            padding: 15px 20px;
            padding-left: 50px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
            font-family: inherit;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #003f91;
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 63, 145, 0.1);
            transform: translateY(-2px);
        }
        
        .form-input:valid {
            border-color: #28a745;
        }
        
        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 1.1rem;
            transition: color 0.3s ease;
        }
        
        .form-input:focus + .input-icon {
            color: #003f91;
        }
        
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #003f91, #0056b3);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 10px;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 63, 145, 0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .login-footer {
            text-align: center;
            padding: 20px 30px;
            border-top: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .login-footer p {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }
        
        .security-badges {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 15px;
        }
        
        .security-badge {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
            color: #28a745;
        }
        
        .security-badge i {
            font-size: 1rem;
        }
        
        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .login-header {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 1.6rem;
            }
            
            .login-form {
                padding: 30px 20px;
            }
            
            .form-input {
                padding: 12px 15px;
                padding-left: 45px;
            }
            
            .btn-login {
                padding: 12px;
                font-size: 1rem;
            }
        }
        
        /* Animaciones de entrada */
        .login-container {
            animation: slideUp 0.8s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-group {
            animation: fadeInLeft 0.6s ease-out;
            animation-fill-mode: both;
        }
        
        .form-group:nth-child(1) {
            animation-delay: 0.2s;
        }
        
        .form-group:nth-child(2) {
            animation-delay: 0.4s;
        }
        
        .btn-login {
            animation: fadeInLeft 0.6s ease-out;
            animation-delay: 0.6s;
            animation-fill-mode: both;
        }
        
        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        /* Estilos para SweetAlert2 */
        .swal2-popup {
            border-radius: 16px !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }
        
        .swal2-title {
            font-size: 1.5rem !important;
            font-weight: 600 !important;
        }
        
        .swal2-confirm {
            border-radius: 8px !important;
            padding: 12px 24px !important;
            font-weight: 600 !important;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Header -->
        <div class="login-header">
            <div class="logo-container">
                <img src="/examen_ingreso/assets/images/Mesa de trabajo 2.png" alt="INCATEC Logo" style="max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain;">
            </div>
            
            <p>Panel Administrativo</p>
        </div>

        <!-- Formulario -->
        <div class="login-form">
            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label for="usuario">
                        <i class="fas fa-user"></i>
                        Usuario
                    </label>
                    <div class="input-wrapper">
                        <input type="text" 
                               id="usuario" 
                               name="usuario" 
                               class="form-input" 
                               placeholder="Ingresa tu usuario" 
                               required 
                               autocomplete="username">
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="clave">
                        <i class="fas fa-lock"></i>
                        Contraseña
                    </label>
                    <div class="input-wrapper">
                        <input type="password" 
                               id="clave" 
                               name="clave" 
                               class="form-input" 
                               placeholder="Ingresa tu contraseña" 
                               required 
                               autocomplete="current-password">
                        <i class="fas fa-lock input-icon"></i>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    Iniciar Sesión
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="login-footer">
            <p>Sistema de Evaluación Digital</p>
            <div class="security-badges">
                <div class="security-badge">
                    <i class="fas fa-shield-alt"></i>
                    <span>Seguro</span>
                </div>
                <div class="security-badge">
                    <i class="fas fa-lock"></i>
                    <span>Encriptado</span>
                </div>
                <div class="security-badge">
                    <i class="fas fa-check-circle"></i>
                    <span>Confiable</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Validación del formulario con SweetAlert2
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const usuario = document.getElementById('usuario').value.trim();
            const clave = document.getElementById('clave').value.trim();
            
            if (!usuario || !clave) {
                e.preventDefault();
                
                Swal.fire({
                    icon: 'warning',
                    title: 'Campos Incompletos',
                    text: 'Por favor, completa todos los campos requeridos.',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#003f91',
                    backdrop: 'rgba(0,0,0,0.4)'
                });
                return;
            }
            
            // Mostrar loading
            const btn = document.querySelector('.btn-login');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
        });

        // Mostrar mensajes PHP con SweetAlert2
        <?php if ($mensaje && $tipo_mensaje): ?>
            document.addEventListener('DOMContentLoaded', function() {
                <?php if ($tipo_mensaje === 'success'): ?>
                    Swal.fire({
                        icon: 'success',
                        title: '¡Acceso Autorizado!',
                        text: '<?= $mensaje ?>',
                        confirmButtonText: 'Continuar',
                        confirmButtonColor: '#28a745',
                        timer: 3000,
                        timerProgressBar: true
                    }).then(() => {
                        window.location.href = '/examen_ingreso/admin/index.php';
                    });
                <?php elseif ($tipo_mensaje === 'error'): ?>
                    Swal.fire({
                        icon: 'error',
                        title: 'Acceso Denegado',
                        text: '<?= $mensaje ?>',
                        confirmButtonText: 'Intentar de nuevo',
                        confirmButtonColor: '#dc3545'
                    });
                <?php elseif ($tipo_mensaje === 'warning'): ?>
                    Swal.fire({
                        icon: 'warning',
                        title: 'Campos Requeridos',
                        text: '<?= $mensaje ?>',
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#ffc107'
                    });
                <?php endif; ?>
            });
        <?php endif; ?>
        
        // Efectos adicionales
        document.addEventListener('DOMContentLoaded', function() {
            // Efecto de focus en inputs
            const inputs = document.querySelectorAll('.form-input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.parentElement.querySelector('label').style.color = '#003f91';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.parentElement.querySelector('label').style.color = '#333';
                });
            });
        });
    </script>
</body>
</html>
