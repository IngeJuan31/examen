<?php
session_start();
require_once 'config/db.php';

$alerta = null;

// Verificar si ya está logueado - SOLO SI NO HAY ERROR
if (isset($_SESSION['participante_id']) && !isset($_GET['error']) && !isset($_GET['logout'])) {
    // Verificar que la sesión es válida antes de redirigir
    try {
        $stmt = $pdo->prepare("SELECT id_participante, nombre FROM participantes WHERE id_participante = ?");
        $stmt->execute([$_SESSION['participante_id']]);
        $participante_valido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($participante_valido) {
            // Redirigir a ver_resultado.php con parámetro para evitar redirección automática
            header('Location: ver_resultado.php?from_index=1');
            exit;
        } else {
            // Sesión inválida, limpiar
            session_destroy();
            session_unset();
            $alerta = ['tipo' => 'warning', 'mensaje' => 'Su sesión ha expirado. Por favor, inicie sesión nuevamente.'];
        }
    } catch (Exception $e) {
        // Error al verificar sesión, limpiar
        session_destroy();
        session_unset();
        $alerta = ['tipo' => 'error', 'mensaje' => 'Error de conexión. Intente nuevamente.'];
    }
}

// Limpiar sesión si se solicita logout
if (isset($_GET['logout'])) {
    session_destroy();
    session_unset();
    $alerta = ['tipo' => 'success', 'mensaje' => 'Sesión cerrada correctamente.'];
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $usuario = trim($_POST['usuario']);
    $clave = trim($_POST['clave']);

    if (empty($usuario) || empty($clave)) {
        $alerta = ['tipo' => 'warning', 'mensaje' => 'Por favor complete todos los campos.'];
    } else {
        try {
            // Verificar si los campos usuario y clave existen
            $stmt_check = $pdo->prepare("SELECT column_name FROM information_schema.columns 
                                        WHERE table_name = 'participantes' 
                                        AND table_schema = 'public' 
                                        AND column_name IN ('usuario', 'clave')");
            $stmt_check->execute();
            $existing_columns = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

            $usuario_exists = in_array('usuario', $existing_columns);
            $clave_exists = in_array('clave', $existing_columns);

            if (!$usuario_exists || !$clave_exists) {
                throw new Exception('Sistema no configurado correctamente. Contacte al administrador.');
            }

            // Buscar participante
            $stmt = $pdo->prepare("SELECT id_participante, nombre, usuario, clave, estado_examen FROM participantes WHERE usuario = ?");
            $stmt->execute([$usuario]);
            $participante = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$participante) {
                $alerta = ['tipo' => 'error', 'mensaje' => 'Usuario no encontrado. Verifique su número de identificación.'];
            } elseif (!password_verify($clave, $participante['clave'])) {
                $alerta = ['tipo' => 'error', 'mensaje' => 'Contraseña incorrecta. Verifique su número de identificación.'];
            } else {
                // Login exitoso
                $_SESSION['participante_id'] = $participante['id_participante'];
                $_SESSION['participante_nombre'] = $participante['nombre'];
                $_SESSION['participante_usuario'] = $participante['usuario'];

                // Redirigir inmediatamente sin mostrar alerta
                header('Location: examen.php?mensaje=login_exitoso');
                exit;
            }
        } catch (Exception $e) {
            $alerta = ['tipo' => 'error', 'mensaje' => 'Error de conexión. Intente nuevamente en unos momentos.'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examen de Ingreso - INCATEC</title>
        <!-- Favicon INCATEC -->
    <link rel="icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link rel="apple-touch-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root {
            --azul-incatec: #003f91;
            --azul-claro: #1e5bb8;
            --azul-ultra: #004aad;
            --rojo-incatec: #d72638;
            --blanco: #ffffff;
            --gris-suave: #f8fafc;
            --gris-medio: #e2e8f0;
            --gris-oscuro: #1a202c;
            --gris-texto: #4a5568;
            --verde-success: #48bb78;
            --sombra-suave: 0 10px 40px rgba(0, 0, 0, 0.1);
            --sombra-intensa: 0 25px 50px rgba(0, 0, 0, 0.15);
            --transicion: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            --transicion-rapida: all 0.2s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', 'Roboto', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, var(--azul-incatec) 25%, #764ba2 75%, #667eea 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        @keyframes gradientShift {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        /* Efectos de fondo animado */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background:
                radial-gradient(circle at 25% 25%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 50%;
            animation: float 20s infinite linear;
        }

        .shape:nth-child(1) {
            width: 80px;
            height: 80px;
            left: 10%;
            animation-delay: 0s;
        }

        .shape:nth-child(2) {
            width: 120px;
            height: 120px;
            left: 80%;
            animation-delay: 5s;
        }

        .shape:nth-child(3) {
            width: 60px;
            height: 60px;
            left: 50%;
            animation-delay: 10s;
        }

        @keyframes float {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                transform: translateY(-100px) rotate(360deg);
                opacity: 0;
            }
        }

        .main-container {
            position: relative;
            z-index: 10;
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 1200px;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: var(--sombra-intensa);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .hero-section {
            background: linear-gradient(135deg, var(--azul-incatec) 0%, var(--azul-ultra) 100%);
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
            color: white;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
            opacity: 0.3;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .hero-icon i {
            font-size: 2.5rem;
            color: white;
        }

        .hero-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
        }

        .hero-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 32px;
            font-weight: 400;
        }

        .features-list {
            list-style: none;
            text-align: left;
            margin-top: 24px;
        }

        .features-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            font-size: 0.95rem;
            opacity: 0.9;
        }

        .features-list i {
            width: 20px;
            color: var(--verde-success);
            font-size: 0.9rem;
        }

        .login-section {
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gris-oscuro);
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .login-subtitle {
            color: var(--gris-texto);
            font-size: 1rem;
            font-weight: 400;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            color: var(--gris-oscuro);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
            letter-spacing: 0.01em;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 16px 20px 16px 52px;
            border: 2px solid var(--gris-medio);
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 400;
            transition: var(--transicion);
            background: var(--blanco);
            color: var(--gris-oscuro);
        }

        .form-input::placeholder {
            color: #a0aec0;
            font-weight: 400;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--azul-incatec);
            box-shadow: 0 0 0 4px rgba(0, 63, 145, 0.1);
            transform: translateY(-1px);
        }

        .form-input:valid {
            border-color: var(--verde-success);
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gris-texto);
            font-size: 1.1rem;
            transition: var(--transicion-rapida);
        }

        .form-input:focus+.input-icon {
            color: var(--azul-incatec);
            transform: translateY(-50%) scale(1.1);
        }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, var(--azul-incatec) 0%, var(--azul-claro) 100%);
            color: var(--blanco);
            border: none;
            padding: 18px 24px;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transicion);
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            letter-spacing: 0.02em;
            position: relative;
            overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, var(--azul-claro) 0%, var(--azul-ultra) 100%);
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(0, 63, 145, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .security-badges {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 32px;
        }

        .badge {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: var(--gris-suave);
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--gris-texto);
            font-weight: 500;
        }

        .badge i {
            color: var(--verde-success);
            font-size: 0.9rem;
        }

        .footer-link {
            text-align: center;
            margin-top: 24px;
        }

        .footer-link a {
            color: var(--gris-texto);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: var(--transicion-rapida);
        }

        .footer-link a:hover {
            color: var(--azul-incatec);
        }

        /* Animaciones de entrada */
        .main-container {
            animation: slideUp 0.8s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero-content>* {
            animation: fadeInLeft 0.6s ease-out;
            animation-fill-mode: both;
        }

        .hero-icon {
            animation-delay: 0.1s;
        }

        .hero-title {
            animation-delay: 0.2s;
        }

        .hero-subtitle {
            animation-delay: 0.3s;
        }

        .features-list {
            animation-delay: 0.4s;
        }

        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .login-section>* {
            animation: fadeInRight 0.6s ease-out;
            animation-fill-mode: both;
        }

        .login-header {
            animation-delay: 0.1s;
        }

        .form-group:nth-child(2) {
            animation-delay: 0.2s;
        }

        .form-group:nth-child(3) {
            animation-delay: 0.3s;
        }

        .btn-login {
            animation-delay: 0.4s;
        }

        .security-badges {
            animation-delay: 0.5s;
        }

        @keyframes fadeInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 968px) {
            .main-container {
                grid-template-columns: 1fr;
                max-width: 500px;
            }

            .hero-section {
                padding: 40px 30px;
            }

            .hero-title {
                font-size: 2rem;
            }

            .login-section {
                padding: 40px 30px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .hero-section,
            .login-section {
                padding: 30px 20px;
            }

            .hero-title {
                font-size: 1.8rem;
            }

            .login-title {
                font-size: 1.6rem;
            }

            .form-input {
                padding: 14px 18px 14px 46px;
            }

            .btn-login {
                padding: 16px 20px;
            }
        }
        
    </style>
</head>

<body>
    <!-- Formas flotantes animadas -->
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <div class="main-container">
        <!-- Sección Hero -->
        <div class="hero-section">
            <div class="hero-content">
                <div class="hero-icon">
                    <img src="/examen_ingreso/assets/images/Mesa de trabajo 2.png" alt="INCATEC Logo" style="max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain;">
                </div>
                <h1 class="hero-title">INCATEC</h1>
                <p class="hero-subtitle">Sistema de Evaluación Digital</p>

                <ul class="features-list">
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Evaluación adaptativa e inteligente</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Resultados inmediatos y precisos</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Sección de Login -->
        <div class="login-section">
            <div class="login-header">
                <h2 class="login-title">Iniciar Sesión</h2>
                <p class="login-subtitle">Accede a tu examen de ingreso</p>
            </div>

            <form method="POST" id="loginForm">
                <input type="hidden" name="login" value="1">

                <div class="form-group">
                    <label class="form-label">Usuario</label>
                    <div class="input-wrapper">
                        <input type="text" name="usuario" class="form-input"
                            placeholder="Tu número de identificación" required autocomplete="username">
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Contraseña</label>
                    <div class="input-wrapper">
                        <input type="password" name="clave" class="form-input"
                            placeholder="Tu número de identificación" required autocomplete="current-password">
                        <i class="fas fa-lock input-icon"></i>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Iniciar Sesión</span>
                </button>
            </form>

            <div class="security-badges">

                <div class="badge">
                    <i class="bi bi-backpack3"></i>
                    <span>Te deseamos suerte en tu examen</span>
                </div>
            </div>

            <div class="footer-link">
                <a href="?logout=1">¿Problemas con tu sesión? Reiniciar</a>
            </div>
        </div>
    </div>

    <?php if ($alerta): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const alertConfig = {
                    success: {
                        icon: 'success',
                        title: '¡Bienvenido!',
                        confirmButtonColor: '#28a745'
                    },
                    warning: {
                        icon: 'warning',
                        title: 'Atención',
                        confirmButtonColor: '#ffc107'
                    },
                    error: {
                        icon: 'error',
                        title: 'Error de acceso',
                        confirmButtonColor: '#dc3545'
                    }
                };

                const config = alertConfig['<?= $alerta['tipo'] ?>'];

                Swal.fire({
                    icon: config.icon,
                    title: config.title,
                    text: '<?= addslashes($alerta['mensaje']) ?>',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: config.confirmButtonColor,
                    allowOutsideClick: false,
                    showClass: {
                        popup: 'animate__animated animate__fadeInDown'
                    },
                    hideClass: {
                        popup: 'animate__animated animate__fadeOutUp'
                    }
                });
            });
        </script>
    <?php endif; ?>

    <script>
        // Variable global para evitar múltiples envíos
        let formSubmitting = false;

        // Validación y procesamiento del formulario optimizado
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            // Prevenir múltiples envíos
            if (formSubmitting) {
                e.preventDefault();
                return false;
            }

            const usuario = document.querySelector('input[name="usuario"]').value.trim();
            const clave = document.querySelector('input[name="clave"]').value.trim();
            const submitBtn = this.querySelector('button[type="submit"]');

            // Validaciones básicas con alertas más elegantes
            if (!usuario || !clave) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Campos requeridos',
                    html: `
                    <div style="text-align: center; padding: 20px;">
                        <i class="fas fa-exclamation-triangle" style="color: #ffc107; font-size: 3rem; margin-bottom: 20px;"></i>
                        <h3 style="color: #1a202c; margin-bottom: 10px;">Información incompleta</h3>
                        <p style="margin: 0; font-size: 1.1rem; color: #4a5568;">Por favor complete todos los campos para continuar.</p>
                    </div>
                `,
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#003f91',
                    width: '450px',
                    backdrop: 'rgba(0,0,0,0.4)',
                    customClass: {
                        popup: 'swal-modern-popup',
                        confirmButton: 'swal-modern-button'
                    },
                });
                return false;
            }

            if (usuario.length < 3 || clave.length < 3) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Datos inválidos',
                    html: `
                    <div style="text-align: center; padding: 20px;">
                        <i class="fas fa-shield-alt" style="color: #d72638; font-size: 3rem; margin-bottom: 20px;"></i>
                        <h3 style="color: #1a202c; margin-bottom: 10px;">Credenciales incorrectas</h3>
                        <p style="margin: 0; font-size: 1.1rem; color: #4a5568;">El usuario y contraseña deben tener al menos 3 caracteres.</p>
                    </div>
                `,
                    confirmButtonText: 'Corregir',
                    confirmButtonColor: '#d72638',
                    width: '450px',
                    backdrop: 'rgba(0,0,0,0.4)'
                });
                return false;
            }

            // Marcar como enviando para evitar doble envío
            formSubmitting = true;

            // Animación de envío elegante
            submitBtn.disabled = true;
            const originalContent = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Verificando...</span>';
            submitBtn.style.background = 'linear-gradient(135deg, #6c757d, #495057)';

            // Loading con diseño ultra moderno
            Swal.fire({
                title: 'Autenticando usuario',
                html: `
                <div style="text-align: center; padding: 40px 20px;">
                    <div style="margin-bottom: 30px; position: relative;">
                        <div style="width: 80px; height: 80px; margin: 0 auto; border-radius: 50%; background: linear-gradient(135deg, #003f91, #1e5bb8); display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 30px rgba(0,63,145,0.3);">
                            <i class="fas fa-user-shield" style="color: white; font-size: 2rem;"></i>
                        </div>
                        <div style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 100px; height: 100px; border: 3px solid transparent; border-top: 3px solid #003f91; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    </div>
                    <h3 style="color: #1a202c; margin-bottom: 15px; font-weight: 600;">Verificando credenciales</h3>
                    <div style="margin-bottom: 20px;">
                        <div style="background: #f8fafc; height: 6px; border-radius: 3px; overflow: hidden; position: relative;">
                            <div style="width: 100%; height: 100%; background: linear-gradient(90deg, #003f91, #1e5bb8, #004aad); animation: progressWave 2s ease-in-out infinite;"></div>
                        </div>
                    </div>
                    <p style="margin: 0; color: #4a5568; font-size: 1rem;">Preparando el entorno de evaluación...</p>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: translateX(-50%) rotate(0deg); }
                        100% { transform: translateX(-50%) rotate(360deg); }
                    }
                    @keyframes progressWave {
                        0% { transform: translateX(-100%); }
                        50% { transform: translateX(0%); }
                        100% { transform: translateX(100%); }
                    }
                </style>
            `,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                width: '500px',
                backdrop: 'rgba(0,0,0,0.6)',
                customClass: {
                    popup: 'swal-loading-popup'
                },
            });

            // Timeout de seguridad mejorado
            setTimeout(() => {
                if (formSubmitting && submitBtn.disabled) {
                    formSubmitting = false;
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalContent;
                    submitBtn.style.background = '';
                    Swal.close();

                    Swal.fire({
                        icon: 'error',
                        title: 'Tiempo de espera agotado',
                        html: `
                        <div style="text-align: center; padding: 20px;">
                            <i class="fas fa-clock" style="color: #d72638; font-size: 3rem; margin-bottom: 20px;"></i>
                            <h3 style="color: #1a202c; margin-bottom: 10px;">Conexión lenta detectada</h3>
                            <p style="margin: 0; font-size: 1.1rem; color: #4a5568;">La conexión está tardando más de lo esperado. Por favor, verifique su conexión a internet e intente nuevamente.</p>
                        </div>
                    `,
                        confirmButtonText: 'Reintentar',
                        confirmButtonColor: '#003f91',
                        width: '500px'
                    });
                }
            }, 12000);

            // Permitir que el formulario se envíe normalmente
            return true;
        });

        // Efectos mejorados de interacción
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-focus elegante
            const firstInput = document.querySelector('input[name="usuario"]');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 500);
            }

            // Efectos de typing en inputs mejorados
            const inputs = document.querySelectorAll('.form-input');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.value.length > 0) {
                        this.style.transform = 'translateY(-1px)';
                        this.style.boxShadow = '0 8px 25px rgba(0,63,145,0.15)';
                    } else {
                        this.style.transform = '';
                        this.style.boxShadow = '';
                    }
                });

                input.addEventListener('blur', function() {
                    if (this.value.length === 0) {
                        this.style.transform = '';
                        this.style.boxShadow = '';
                    }
                });

                // Mejorar el manejo de Enter
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !formSubmitting) {
                        e.preventDefault();

                        // Si estamos en el primer campo y está vacío, no enviar
                        if (this.name === 'usuario' && !this.value.trim()) {
                            return;
                        }

                        // Si estamos en el primer campo y tiene valor, ir al segundo
                        if (this.name === 'usuario' && this.value.trim()) {
                            const claveInput = document.querySelector('input[name="clave"]');
                            if (claveInput && !claveInput.value.trim()) {
                                claveInput.focus();
                                return;
                            }
                        }

                        // Si llegamos aquí y ambos campos tienen valor, enviar
                        const usuarioValue = document.querySelector('input[name="usuario"]').value.trim();
                        const claveValue = document.querySelector('input[name="clave"]').value.trim();

                        if (usuarioValue && claveValue) {
                            const form = document.getElementById('loginForm');
                            const submitEvent = new Event('submit', {
                                cancelable: true,
                                bubbles: true
                            });
                            form.dispatchEvent(submitEvent);
                        }
                    }
                });
            });

            // Efectos hover en badges mejorados
            const badges = document.querySelectorAll('.badge');
            badges.forEach((badge, index) => {
                badge.style.animationDelay = `${0.6 + (index * 0.1)}s`;

                badge.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px) scale(1.05)';
                    this.style.boxShadow = '0 8px 25px rgba(0,0,0,0.1)';
                });

                badge.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '';
                });
            });

            // Animación de entrada de elementos de la lista
            const featureItems = document.querySelectorAll('.features-list li');
            featureItems.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    item.style.transition = 'all 0.6s cubic-bezier(0.4,0,0.2,1)';
                    item.style.opacity = '1';
                    item.style.transform = 'translateX(0)';
                }, 400 + (index * 150));
            });

            // Agregar estilos dinámicos mejorados
            const dynamicStyles = document.createElement('style');
            dynamicStyles.textContent = `
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                20% { transform: translateX(-8px); }
                40% { transform: translateX(8px); }
                60% { transform: translateX(-4px); }
                80% { transform: translateX(4px); }
            }
            
            .swal-modern-popup {
                border-radius: 20px !important;
                box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25) !important;
                backdrop-filter: blur(20px) !important;
            }
            
            .swal-modern-button {
                border-radius: 12px !important;
                padding: 14px 28px !important;
                font-weight: 600 !important;
                font-size: 1rem !important;
                transition: all 0.3s ease !important;
            }
            
            .swal-modern-button:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 8px 25px rgba(0,63,145,0.3) !important;
            }
            
            .swal-loading-popup {
                border-radius: 24px !important;
                backdrop-filter: blur(15px) !important;
                box-shadow: 0 30px 60px -12px rgba(0,0,0,0.3) !important;
            }
            
            .hero-content > *, .login-section > * {
                opacity: 0;
                animation: fadeInUp 0.8s cubic-bezier(0.4,0,0.2,1) forwards;
            }
            
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        `;
            document.head.appendChild(dynamicStyles);

            // Animación de entrada suave de la página
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.8s ease-out';
                document.body.style.opacity = '1';
            }, 100);

            // Efecto de parallax sutil en el hero
            window.addEventListener('scroll', function() {
                const scrolled = window.pageYOffset;
                const heroContent = document.querySelector('.hero-content');
                if (heroContent) {
                    heroContent.style.transform = `translateY(${scrolled * 0.1}px)`;
                }
            });

            // Detector de errores de PHP para limpiar el estado
            <?php if ($alerta && $alerta['tipo'] === 'error'): ?>
                // Si hay error PHP, resetear el estado del formulario
                setTimeout(() => {
                    formSubmitting = false;
                    const submitBtn = document.querySelector('.btn-login');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> <span>Iniciar Sesión</span>';
                        submitBtn.style.background = '';
                    }
                    Swal.close();
                }, 1000);
            <?php endif; ?>
        });
    </script>

</body>

</html>