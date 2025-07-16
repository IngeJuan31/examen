<?php

/**
 * Sistema de Logout - Página de Cierre de Sesión
 * Version: 2.0
 * Autor: Sistema de Exámenes
 * Descripción: Página profesional para cerrar sesión con resumen de resultados
 */

// Configuración de sesión segura
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1);
session_start();

// Incluir configuración de base de datos
require_once 'config/db.php';

class LogoutManager
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene el último resultado del participante
     * @param int $participante_id ID del participante
     * @return array|null Información del resultado
     */
    public function obtenerUltimoResultado($participante_id)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.nombre, 
                    p.apellido, 
                    r.puntaje_total, 
                    r.total_preguntas, 
                    r.respuestas_correctas, 
                    r.porcentaje, 
                    r.fecha_realizacion,
                    ae.nivel_dificultad as nivel_examen,
                    COUNT(r.id_resultado) as total_examenes
                FROM participantes p
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                LEFT JOIN resultados r ON p.id_participante = r.participante_id
                WHERE p.id_participante = ?
                GROUP BY p.id_participante
                ORDER BY r.fecha_realizacion DESC
                LIMIT 1
            ");

            $stmt->execute([$participante_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error al obtener resultado: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calcula la clasificación del resultado
     * @param float $porcentaje Porcentaje obtenido
     * @return array Información de la clasificación
     */
    public function calcularClasificacion($porcentaje)
    {
        $clasificaciones = [
            ['min' => 95, 'clase' => 'excelente', 'texto' => '¡Sobresaliente!', 'icono' => 'fas fa-star'],
            ['min' => 85, 'clase' => 'muy-bueno', 'texto' => '¡Excelente!', 'icono' => 'fas fa-trophy'],
            ['min' => 75, 'clase' => 'bueno', 'texto' => '¡Muy Bueno!', 'icono' => 'fas fa-thumbs-up'],
            ['min' => 60, 'clase' => 'regular', 'texto' => 'Regular', 'icono' => 'fas fa-check-circle'],
            ['min' => 0, 'clase' => 'deficiente', 'texto' => 'Necesita Mejorar', 'icono' => 'fas fa-redo']
        ];

        foreach ($clasificaciones as $clasificacion) {
            if ($porcentaje >= $clasificacion['min']) {
                return $clasificacion;
            }
        }

        return $clasificaciones[count($clasificaciones) - 1];
    }

    /**
     * Cierra la sesión de forma segura
     */
    public function cerrarSesion()
    {
        // Limpiar todas las variables de sesión
        $_SESSION = [];

        // Destruir la cookie de sesión
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        // Destruir la sesión
        session_destroy();

        // Regenerar ID de sesión por seguridad
        session_start();
        session_regenerate_id(true);
        session_destroy();
    }
}

// Inicializar el gestor de logout
$logoutManager = new LogoutManager($pdo);

// Verificar si hay un participante logueado
$participante_id = $_SESSION['participante_id'] ?? null;
$mostrar_resultado = false;
$resultado_info = null;

// Validar ID de participante
if ($participante_id && is_numeric($participante_id)) {
    $resultado_info = $logoutManager->obtenerUltimoResultado($participante_id);
    $mostrar_resultado = $resultado_info && $resultado_info['puntaje_total'] !== null;
}

// Procesar confirmación de logout
if (isset($_POST['confirmar_logout']) && $_POST['confirmar_logout'] === 'si') {
    // Validar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Error de seguridad: Token CSRF inválido');
    }

    $logoutManager->cerrarSesion();
    header('Location: index.php?logout=success');
    exit;
}

// Generar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Función para escapar datos de salida
function escape($data)
{
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Función para formatear fecha
function formatearFecha($fecha)
{
    return date('d/m/Y H:i', strtotime($fecha));
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Cerrar Sesión - Sistema de Exámenes Profesional</title>

    <!-- Preload critical resources -->
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" as="style">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style">
        <!-- Favicon INCATEC -->
    <link rel="icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link rel="apple-touch-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">

    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --danger-gradient: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --card-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            --border-radius: 20px;
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--primary-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            line-height: 1.6;
        }

        .logout-container {
            width: 100%;
            max-width: 700px;
            position: relative;
        }

        .logout-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 50px;
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }

        .logout-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .logout-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.15);
        }

        .header-section {
            margin-bottom: 40px;
        }

        .header-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        .header-icon i {
            color: white;
            font-size: 2rem;
        }

        .main-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .subtitle {
            color: #7f8c8d;
            font-size: 1.1rem;
            font-weight: 400;
        }

        .resultado-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            padding: 30px;
            margin: 30px 0;
            border-left: 4px solid #667eea;
        }

        .resultado-header {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
        }

        .resultado-header h4 {
            color: #2c3e50;
            font-weight: 600;
            margin: 0 0 0 10px;
        }

        .puntaje-circle {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px auto;
            font-size: 28px;
            font-weight: 700;
            color: white;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }

        .puntaje-circle::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: conic-gradient(transparent, rgba(255, 255, 255, 0.1));
            animation: rotate 3s linear infinite;
        }

        @keyframes rotate {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .puntaje-text {
            position: relative;
            z-index: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #7f8c8d;
            font-weight: 500;
        }

        .resultado-badge {
            display: inline-flex;
            align-items: center;
            padding: 12px 25px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            margin: 15px 0;
            color: white;
            transition: var(--transition);
        }

        .resultado-badge i {
            margin-right: 8px;
        }

        .excelente {
            background: var(--success-gradient);
        }

        .muy-bueno {
            background: var(--info-gradient);
        }

        .bueno {
            background: linear-gradient(135deg, #36d1dc, #5b86e5);
        }

        .regular {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .deficiente {
            background: var(--danger-gradient);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .info-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
        }

        .info-item i {
            margin-right: 10px;
            color: #667eea;
        }

        .actions-section {
            margin-top: 40px;
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn-custom {
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 180px;
            justify-content: center;
        }

        .btn-primary {
            background: var(--success-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(17, 153, 142, 0.3);
            color: white;
        }

        .btn-danger {
            background: var(--danger-gradient);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(252, 70, 107, 0.3);
            color: white;
        }

        .btn-info {
            background: var(--info-gradient);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(79, 172, 254, 0.3);
            color: white;
        }

        .footer-section {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid #e9ecef;
        }

        .footer-text {
            color: #7f8c8d;
            font-size: 0.9rem;
            font-weight: 400;
        }

        .security-notice {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            font-size: 0.9rem;
        }

        /* Estilos personalizados para SweetAlert2 */
        .swal2-popup {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            border-radius: 20px !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15) !important;
        }

        .swal2-title {
            font-weight: 700 !important;
            color: #2c3e50 !important;
            font-size: 1.8rem !important;
        }

        .swal2-html-container {
            font-size: 1.1rem !important;
            color: #7f8c8d !important;
            line-height: 1.6 !important;
        }

        .swal2-confirm {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%) !important;
            border: none !important;
            border-radius: 50px !important;
            font-weight: 600 !important;
            font-size: 1rem !important;
            padding: 12px 30px !important;
            box-shadow: 0 10px 25px rgba(252, 70, 107, 0.3) !important;
            transition: all 0.3s ease !important;
        }

        .swal2-confirm:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 15px 35px rgba(252, 70, 107, 0.4) !important;
        }

        .swal2-cancel {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%) !important;
            border: none !important;
            border-radius: 50px !important;
            font-weight: 600 !important;
            font-size: 1rem !important;
            padding: 12px 30px !important;
            color: white !important;
            box-shadow: 0 10px 25px rgba(108, 117, 125, 0.3) !important;
            transition: all 0.3s ease !important;
        }

        .swal2-cancel:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 15px 35px rgba(108, 117, 125, 0.4) !important;
        }

        .swal2-icon.swal2-question {
            border-color: #667eea !important;
            color: #667eea !important;
        }

        .swal2-icon.swal2-success {
            border-color: #11998e !important;
            color: #11998e !important;
        }

        .swal2-icon.swal2-error {
            border-color: #fc466b !important;
            color: #fc466b !important;
        }

        .swal2-loader {
            border-color: #667eea transparent #667eea transparent !important;
        }

        .swal2-backdrop-show {
            backdrop-filter: blur(5px) !important;
        }

        @media (max-width: 768px) {
            .logout-card {
                padding: 30px 20px;
            }

            .main-title {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .actions-section {
                flex-direction: column;
                align-items: center;
            }

            .btn-custom {
                width: 100%;
                max-width: 280px;
            }
        }

        @media (max-width: 480px) {
            .puntaje-circle {
                width: 120px;
                height: 120px;
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="logout-container">
        <div class="logout-card">
            <div class="header-section">
                <div class="header-icon">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <h1 class="main-title">¡Hasta pronto!</h1>
                <p class="subtitle">Gracias por usar nuestro sistema de exámenes</p>
            </div>

            <?php if ($mostrar_resultado && $resultado_info): ?>
                <div class="resultado-section">
                    <div class="resultado-header">
                        <i class="fas fa-trophy text-warning fa-2x"></i>
                        <h4>Resumen de tu último examen</h4>
                    </div>

                    <div class="puntaje-circle">
                        <div class="puntaje-text">
                            <?php echo number_format($resultado_info['porcentaje'], 1); ?>%
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo escape($resultado_info['respuestas_correctas']); ?></div>
                            <div class="stat-label">Respuestas Correctas</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo escape($resultado_info['total_preguntas']); ?></div>
                            <div class="stat-label">Total de Preguntas</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($resultado_info['puntaje_total'], 1); ?></div>
                            <div class="stat-label">Puntaje Final</div>
                        </div>
                    </div>

                    <?php
                    $clasificacion = $logoutManager->calcularClasificacion($resultado_info['porcentaje']);
                    ?>

                    <div class="resultado-badge <?php echo $clasificacion['clase']; ?>">
                        <i class="<?php echo $clasificacion['icono']; ?>"></i>
                        <?php echo $clasificacion['texto']; ?>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Realizado: <?php echo formatearFecha($resultado_info['fecha_realizacion']); ?></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-layer-group"></i>
                            <span>Nivel: <?php echo escape(ucfirst($resultado_info['nivel_examen'] ?? 'No asignado')); ?></span>
                        </div>
                    </div>
                </div>

                <div class="actions-section">
                    <a href="ver_resultado.php" class="btn-custom btn-info">
                        <i class="fas fa-chart-bar"></i>
                        Ver Resultado Detallado
                    </a>

                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="confirmar_logout" value="si">
                        <button type="submit" class="btn-custom btn-danger" onclick="return confirm('¿Estás seguro de que deseas cerrar sesión?')">
                            <i class="fas fa-sign-out-alt"></i>
                            Cerrar Sesión
                        </button>
                    </form>
                </div>

            <?php else: ?>
                <div class="resultado-section">
                    <div class="resultado-header">
                        <i class="fas fa-info-circle text-info fa-2x"></i>
                        <h4>Información de Sesión</h4>
                    </div>

                    <p class="text-muted mb-4">
                        <?php if ($participante_id): ?>
                            Aún no has rendido ningún examen. ¡Te invitamos a comenzar!
                        <?php else: ?>
                            Tu sesión está a punto de finalizar. ¿Deseas continuar?
                        <?php endif; ?>
                    </p>

                    <div class="actions-section">
                        <?php if ($participante_id): ?>
                            <a href="examen.php" class="btn-custom btn-primary">
                                <i class="fas fa-pencil-alt"></i>
                                Iniciar Examen
                            </a>
                        <?php endif; ?>

                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="confirmar_logout" value="si">
                            <button type="submit" class="btn-custom btn-danger" onclick="return confirm('¿Estás seguro de que deseas cerrar sesión?')">
                                <i class="fas fa-sign-out-alt"></i>
                                Confirmar Cierre
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <div class="security-notice">
                <i class="fas fa-shield-alt"></i>
                <strong>Nota de Seguridad:</strong> Al cerrar sesión, todos los datos temporales serán eliminados de forma segura.
            </div>

            <div class="footer-section">
                <p class="footer-text">
                    <i class="fas fa-book"></i>
                    Sistema Examen de Ingreso
                </p>
                <p class="footer-text">
                    <small>INCATEC - <span id="anio-actual"></span></small>
                </p>
            </div>
        </div>
    </div>

    <!-- Scripts -->

    <script>
        document.getElementById('anio-actual').textContent = new Date().getFullYear();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js"></script>

    <script>
        // Configuración global de SweetAlert2
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Interceptar todos los formularios de logout
            const logoutForms = document.querySelectorAll('form');

            logoutForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault(); // Prevenir envío automático

                    // SweetAlert2 para confirmación de logout
                    Swal.fire({
                        title: '¿Cerrar sesión?',
                        text: '¿Estás seguro de que deseas cerrar sesión?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, cerrar sesión',
                        cancelButtonText: 'Cancelar',
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Si confirma, enviar el formulario
                            form.submit();
                        }
                    });
                });
            });

            // Interceptar el evento beforeunload para cambios no guardados
            let hasUnsavedChanges = false;

            // Detectar cambios en formularios (opcional)
            const inputs = document.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.addEventListener('change', () => {
                    hasUnsavedChanges = true;
                });
            });

            // Reemplazar la alerta nativa de beforeunload
            window.addEventListener('beforeunload', function(e) {
                if (hasUnsavedChanges) {
                    // Navegadores modernos ignoran el mensaje personalizado
                    // pero aún muestran su propia alerta
                    e.preventDefault();
                    e.returnValue = ''; // Requerido para Chrome
                }
            });

            // Para navegación interna, usar SweetAlert2
            const links = document.querySelectorAll('a[href]');
            links.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (hasUnsavedChanges && !link.href.includes('#') && !link.href.includes('logout')) {
                        e.preventDefault();

                        Swal.fire({
                            title: '¿Abandonar página?',
                            text: 'Es posible que los cambios no se guarden.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Salir sin guardar',
                            cancelButtonText: 'Permanecer aquí',
                            confirmButtonColor: '#ffc107',
                            cancelButtonColor: '#6c757d'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                hasUnsavedChanges = false; // Resetear para evitar doble alerta
                                window.location.href = link.href;
                            }
                        });
                    }
                });
            });

            // Animación suave para elementos con efecto stagger
            const cards = document.querySelectorAll('.stat-card, .info-item');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px) scale(0.95)';

                setTimeout(() => {
                    card.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0) scale(1)';
                }, index * 150);
            });
        });



        // Función para marcar que hay cambios sin guardar (usar cuando sea necesario)
        function markUnsavedChanges() {
            hasUnsavedChanges = true;
        }

        // Función para marcar que los cambios fueron guardados
        function markChangesSaved() {
            hasUnsavedChanges = false;
        }

        // Interceptar todas las alertas nativas del navegador (método avanzado)
        const originalAlert = window.alert;
        const originalConfirm = window.confirm;

        window.alert = function(message) {
            Swal.fire({
                title: 'Información',
                text: message,
                icon: 'info',
                confirmButtonText: 'OK',
                confirmButtonColor: '#667eea'
            });
        };

        window.confirm = function(message) {
            return Swal.fire({
                title: 'Confirmación',
                text: message,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí',
                cancelButtonText: 'No',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                return result.isConfirmed;
            });
        };

        // Prevenir navegación accidental con advertencia
        window.addEventListener('beforeunload', function(e) {
            if (!sessionStorage.getItem('logout-confirmed')) {
                e.preventDefault();
                e.returnValue = '';
                sessionStorage.setItem('show-navigation-warning', 'true');
            }
        });

        // Mostrar advertencia de navegación si se detecta
        if (sessionStorage.getItem('show-navigation-warning')) {
            sessionStorage.removeItem('show-navigation-warning');
            setTimeout(() => {
                Toast.fire({
                    icon: 'warning',
                    title: '⚠️ Cuidado con cerrar la ventana inesperadamente'
                });
            }, 500);
        }

        // Función para confirmar logout y evitar advertencias adicionales
        function confirmarLogout() {
            sessionStorage.setItem('logout-confirmed', 'true');
        }

        // Agregar evento a formularios para marcar logout confirmado
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', confirmarLogout);
        });

        // Agregar animaciones CSS dinámicamente
        const style = document.createElement('style');
        style.textContent = `
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
            
            @keyframes slideInRight {
                from {
                    opacity: 0;
                    transform: translateX(50px);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }
            
            .logout-card {
                animation: fadeInUp 0.8s ease-out;
            }
            
            .stat-card {
                animation: slideInRight 0.6s ease-out forwards;
            }
            
            .info-item {
                animation: fadeInUp 0.7s ease-out forwards;
            }
            
            /* Mejorar el hover de botones */
            .btn-custom:hover {
                transform: translateY(-3px) scale(1.02);
            }
            
            /* Efecto ripple para botones */
            .btn-custom {
                position: relative;
                overflow: hidden;
            }
            
            .btn-custom::before {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                width: 0;
                height: 0;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.3);
                transition: width 0.6s, height 0.6s;
                transform: translate(-50%, -50%);
                z-index: 1;
            }
            
            .btn-custom:active::before {
                width: 300px;
                height: 300px;
            }
        `;
        document.head.appendChild(style);

        // Funcionalidad adicional: detectar inactividad
        let inactivityTimer;
        let inactivityWarned = false;

        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityWarned = false;
            inactivityTimer = setTimeout(() => {
                if (!inactivityWarned) {
                    inactivityWarned = true;
                    Toast.fire({
                        icon: 'warning',
                        title: 'Sesión inactiva detectada ⏰',
                        text: 'Tu sesión expirará pronto por inactividad'
                    });
                }
            }, 300000); // 5 minutos
        }

        // Eventos para detectar actividad del usuario
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'].forEach(event => {
            document.addEventListener(event, resetInactivityTimer, true);
        });

        // Inicializar timer de inactividad
        resetInactivityTimer();

        // Debug: Log para verificar que todo funciona
        console.log('🎉 Sistema de logout profesional cargado correctamente');
        console.log('✅ SweetAlert2 configurado');
        console.log('✅ Interceptores de alerta nativos activos');
        console.log('✅ Sistema de inactividad iniciado');
    </script>

</body>
</html>