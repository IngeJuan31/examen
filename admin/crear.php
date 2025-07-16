<?php
require_once '../config/db.php';
require_once '../controllers/verificar_sesion.php';
require_once '../controllers/permisos.php';
verificarPermiso('COLABORADORES'); // Cambia el permiso según la vista

$mensaje = '';
$tipo_mensaje = '';
$roles = [];

// Cargar roles desde la BD
try {
    $stmt = $pdo->query("SELECT id_rol, nombre_rol FROM roles WHERE estado = true ORDER BY nombre_rol");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = "Error cargando roles: " . $e->getMessage();
    $tipo_mensaje = 'error';
}

// Procesar envío
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $usuario = trim($_POST['usuario']);
    $clave = trim($_POST['clave']);
    $cedula = trim($_POST['cedula']);
    $id_rol = (int)$_POST['id_rol'];

    if ($nombre && $usuario && $clave && $cedula && $id_rol) {
        // Validar que el usuario no exista
        try {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios_admin WHERE usuario = :usuario");
            $stmt_check->execute([':usuario' => $usuario]);
            $usuario_existe = $stmt_check->fetchColumn();

            if ($usuario_existe > 0) {
                $mensaje = "El usuario '$usuario' ya existe en el sistema.";
                $tipo_mensaje = 'warning';
            } else {
                $clave_hash = password_hash($clave, PASSWORD_BCRYPT);

                $sql = "INSERT INTO usuarios_admin (nombre, usuario, clave, cedula, id_rol) 
                        VALUES (:nombre, :usuario, :clave, :cedula, :id_rol)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':usuario' => $usuario,
                    ':clave' => $clave_hash,
                    ':cedula' => $cedula,
                    ':id_rol' => $id_rol
                ]);
                $mensaje = "Usuario '$nombre' creado correctamente.";
                $tipo_mensaje = 'success';
            }
        } catch (PDOException $e) {
            $mensaje = "Error al crear el usuario: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    } else {
        $mensaje = "Por favor, completa todos los campos requeridos.";
        $tipo_mensaje = 'warning';
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Usuario Admin - INCATEC</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --azul-incatec: #2196F3;
            --verde-success: #4CAF50;
            --rojo-danger: #f44336;
            --naranja-warning: #ff9800;
            --purpura-info: #9c27b0;
            --gris-suave: #f8f9fa;
            --gris-oscuro: #333;
            --blanco: #ffffff;
            --sombra-suave: 0 2px 8px rgba(0, 0, 0, 0.1);
            --sombra-hover: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--gris-suave) 0%, #e3f2fd 100%);
            color: var(--gris-oscuro);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
        }

        .header {
            background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
            color: white;
            padding: 40px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: var(--sombra-suave);
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="2"/><circle cx="30" cy="30" r="20" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="1"/><circle cx="70" cy="70" r="15" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="1"/></svg>') no-repeat center;
            opacity: 0.3;
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.2rem;
        }

        .nav-links {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--sombra-suave);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .nav-link {
            background: var(--gris-suave);
            color: var(--gris-oscuro);
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .nav-link:hover {
            background: var(--azul-incatec);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--sombra-hover);
        }

        .nav-back-btn {
            background: linear-gradient(135deg, #6c757d, #5a6268) !important;
            color: white !important;
            position: relative;
            overflow: hidden;
        }

        .nav-back-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .nav-back-btn:hover::before {
            left: 100%;
        }

        .nav-back-btn:hover {
            background: linear-gradient(135deg, #5a6268, #495057) !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.3);
        }

        .nav-back-btn:hover i {
            transform: translateX(-3px);
            transition: transform 0.3s ease;
        }

        .main-content {
            background: white;
            border-radius: 16px;
            box-shadow: var(--sombra-suave);
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }

        .form-container {
            padding: 40px;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section h3 {
            color: var(--azul-incatec);
            font-size: 1.3rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gris-oscuro);
            font-size: 1rem;
        }

        .form-group label i {
            margin-right: 8px;
            color: var(--azul-incatec);
            width: 16px;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--gris-suave);
            font-family: inherit;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--azul-incatec);
            background: white;
            box-shadow: 0 0 0 4px rgba(33, 150, 243, 0.1);
        }

        .form-input:valid {
            border-color: var(--verde-success);
        }

        .form-select {
            cursor: pointer;
        }

        .strength-meter {
            margin-top: 8px;
            height: 6px;
            background: #e1e5e9;
            border-radius: 3px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            background: var(--rojo-danger);
            transition: all 0.3s ease;
            border-radius: 3px;
        }

        .strength-text {
            font-size: 0.85rem;
            margin-top: 5px;
            color: #666;
            font-weight: 500;
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--verde-success), #388e3c);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(76, 175, 80, 0.3);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .header {
                padding: 25px;
            }

            .header h1 {
                font-size: 2rem;
            }

            .form-container {
                padding: 25px;
            }

            .nav-links {
                flex-direction: column;
            }
        }

        /* Animaciones */
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

        .form-group {
            animation: fadeIn 0.6s ease-out;
            animation-fill-mode: both;
        }

        .form-group:nth-child(1) {
            animation-delay: 0.1s;
        }

        .form-group:nth-child(2) {
            animation-delay: 0.2s;
        }

        .form-group:nth-child(3) {
            animation-delay: 0.3s;
        }

        .form-group:nth-child(4) {
            animation-delay: 0.4s;
        }

        .form-group:nth-child(5) {
            animation-delay: 0.5s;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Estilos para SweetAlert2 */
        .swal2-popup {
            border-radius: 16px !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            border: 2px solid var(--azul-incatec) !important;
        }

        .swal2-title {
            font-size: 1.5rem !important;
            font-weight: 600 !important;
            color: var(--azul-incatec) !important;
        }

        .swal2-confirm {
            border-radius: 8px !important;
            padding: 12px 24px !important;
            font-weight: 600 !important;
            background: var(--azul-incatec) !important;
        }

        .swal2-confirm:hover {
            background: #1976d2 !important;
        }

        /* Estilos para iconos de fuerza de contraseña */
        .password-requirements {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            border-left: 4px solid var(--azul-incatec);
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            margin-bottom: 5px;
            color: #666;
        }

        .requirement.valid {
            color: var(--verde-success);
        }

        .requirement.valid i {
            color: var(--verde-success);
        }

        .requirement i {
            color: #ccc;
            font-size: 0.8rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1>
                    <i class="fas fa-user-plus"></i>
                    Crear Usuario Admin
                </h1>
                <p>Sistema de Gestión INCATEC</p>
            </div>
        </div>

        <!-- Navegación -->
        <div class="nav-links">
            <button onclick="volverConConfirmacion()" class="nav-link nav-back-btn">
                <i class="fas fa-arrow-left"></i>
                Volver
            </button>
            <a href="/examen_ingreso/controllers/asignar_permisos.php" class="nav-link">
                <i class="fas fa-users"></i>
                Asignar permisos a Usuarios
            </a>




        </div>

        <!-- Contenido Principal -->
        <div class="main-content">
            <div class="form-container">
                <div class="form-section">
                    <h3>
                        <i class="fas fa-user-tie"></i>
                        Información del Usuario
                    </h3>
                </div>

                <form method="POST" id="formCrearUsuario">
                    <div class="form-group">
                        <label for="nombre">
                            <i class="fas fa-user"></i> Nombre completo
                        </label>
                        <input type="text"
                            id="nombre"
                            name="nombre"
                            class="form-input"
                            placeholder="Ej: Juan Manuel Pérez"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="usuario">
                            <i class="fas fa-at"></i> Usuario
                        </label>
                        <input type="text"
                            id="usuario"
                            name="usuario"
                            class="form-input"
                            placeholder="Ej: jmanuel.perez"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="clave">
                            <i class="fas fa-lock"></i> Contraseña
                        </label>
                        <input type="password"
                            id="clave"
                            name="clave"
                            class="form-input"
                            placeholder="Mínimo 8 caracteres"
                            required>
                        <div class="strength-meter">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                        <div class="strength-text" id="strengthText">Ingresa una contraseña</div>
                    </div>

                    <div class="form-group">
                        <label for="cedula">
                            <i class="fas fa-id-card"></i> Cédula
                        </label>
                        <input type="text"
                            id="cedula"
                            name="cedula"
                            class="form-input"
                            placeholder="Ej: 12345678"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="id_rol">
                            <i class="fas fa-user-tag"></i> Rol
                        </label>
                        <select id="id_rol" name="id_rol" class="form-select" required>
                            <option value="">Seleccione un rol</option>
                            <?php foreach ($roles as $rol): ?>
                                <option value="<?= $rol['id_rol'] ?>">
                                    <?= htmlspecialchars($rol['nombre_rol']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Crear Usuario
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Mostrar notificación si hay mensaje
        <?php if ($mensaje && $tipo_mensaje): ?>
            document.addEventListener('DOMContentLoaded', function() {
                mostrarNotificacion('<?= $tipo_mensaje ?>', '<?= addslashes($mensaje) ?>');
            });
        <?php endif; ?>

        // Función para mostrar notificaciones
        function mostrarNotificacion(tipo, mensaje) {
            const config = {
                customClass: {
                    popup: 'swal-incatec-popup',
                    title: 'swal-incatec-title',
                    confirmButton: 'swal-incatec-confirm'
                },
                backdrop: 'rgba(0,0,0,0.4)',
                allowOutsideClick: false
            };

            switch (tipo) {
                case 'success':
                    Swal.fire({
                        icon: 'success',
                        title: '¡Éxito!',
                        html: `
                            <div style="text-align: center; padding: 20px;">
                                <i class="fas fa-check-circle" style="color: #28a745; font-size: 2rem;"></i>
                                <p style="margin-top: 10px; font-size: 1.1rem;">${mensaje}</p>
                            </div>
                        `,
                        ...config
                    });
                    break;
                case 'error':
                    Swal.fire({
                        icon: 'error',
                        title: '¡Error!',
                        html: `
                            <div style="text-align: center; padding: 20px;">
                                <i class="fas fa-exclamation-circle" style="color: #dc3545; font-size: 2rem;"></i>
                                <p style="margin-top: 10px; font-size: 1.1rem;">${mensaje}</p>
                            </div>
                        `,
                        ...config
                    });
                    break;
                case 'warning':
                    Swal.fire({
                        icon: 'warning',
                        title: 'Advertencia',
                        html: `
                            <div style="text-align: center; padding: 20px;">
                                <i class="fas fa-exclamation-triangle" style="color: #ffc107; font-size: 2rem;"></i>
                                <p style="margin-top: 10px; font-size: 1.1rem;">${mensaje}</p>
                            </div>
                        `,
                        ...config
                    });
                    break;
            }
        }

        // ✅ FUNCIÓN PARA VOLVER CON CONFIRMACIÓN
        function volverConConfirmacion() {
            // Verificar si hay datos en el formulario
            const nombre = document.getElementById('nombre').value.trim();
            const usuario = document.getElementById('usuario').value.trim();
            const clave = document.getElementById('clave').value.trim();
            const cedula = document.getElementById('cedula').value.trim();
            const rol = document.getElementById('id_rol').value;

            const hayDatos = nombre || usuario || clave || cedula || rol;

            if (hayDatos) {
                Swal.fire({
                    title: '¿Salir sin guardar?',
                    html: `
                        <div style="text-align: center; padding: 20px;">
                            <i class="fas fa-exclamation-triangle" style="color: #ffc107; font-size: 3rem; margin-bottom: 20px;"></i>
                            <h3 style="color: #333; margin-bottom: 15px;">Tienes datos sin guardar</h3>
                            <p style="color: #666; font-size: 1rem; margin-bottom: 20px;">
                                Si sales ahora, perderás la información que has ingresado.
                            </p>
                            <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px;">
                                <small style="color: #856404;">
                                    <i class="fas fa-info-circle"></i> 
                                    Asegúrate de guardar tus cambios antes de salir
                                </small>
                            </div>
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Sí, salir',
                    cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    backdrop: 'rgba(0,0,0,0.4)',
                    customClass: {
                        popup: 'swal-incatec-popup',
                        confirmButton: 'swal-confirm-danger'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        volverPagina();
                    }
                });
            } else {
                // Si no hay datos, volver directamente
                volverPagina();
            }
        }

        // ✅ FUNCIÓN PARA VOLVER A LA PÁGINA ANTERIOR
        function volverPagina() {
            // Verificar si hay historial de navegación
            if (window.history.length > 1) {
                window.history.back();
            } else {
                // Si no hay historial, ir al dashboard
                window.location.href = '/examen_ingreso/admin/index.php';
            }
        }

        // Validar fuerza de la contraseña
        const claveInput = document.getElementById('clave');
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');

        claveInput.addEventListener('input', function() {
            const valor = claveInput.value;
            let fuerza = 0;

            // Criterios de fuerza
            if (valor.length >= 8) fuerza++;
            if (/[A-Z]/.test(valor)) fuerza++;
            if (/[a-z]/.test(valor)) fuerza++;
            if (/[0-9]/.test(valor)) fuerza++;
            if (/[\W_]/.test(valor)) fuerza++;

            // Actualizar barra de fuerza
            switch (fuerza) {
                case 0:
                case 1:
                    strengthFill.style.width = '20%';
                    strengthFill.style.background = '#dc3545';
                    strengthText.textContent = 'Muy débil';
                    strengthText.style.color = '#dc3545';
                    break;
                case 2:
                    strengthFill.style.width = '40%';
                    strengthFill.style.background = '#ffc107';
                    strengthText.textContent = 'Débil';
                    strengthText.style.color = '#ffc107';
                    break;
                case 3:
                    strengthFill.style.width = '60%';
                    strengthFill.style.background = '#fd7e14';
                    strengthText.textContent = 'Regular';
                    strengthText.style.color = '#fd7e14';
                    break;
                case 4:
                    strengthFill.style.width = '80%';
                    strengthFill.style.background = '#28a745';
                    strengthText.textContent = 'Buena';
                    strengthText.style.color = '#28a745';
                    break;
                case 5:
                    strengthFill.style.width = '100%';
                    strengthFill.style.background = '#007bff';
                    strengthText.textContent = 'Muy fuerte';
                    strengthText.style.color = '#007bff';
                    break;
            }
        });

        // ✅ VALIDACIÓN DEL FORMULARIO AL ENVIAR
        document.getElementById('formCrearUsuario').addEventListener('submit', function(e) {
            const nombre = document.getElementById('nombre').value.trim();
            const usuario = document.getElementById('usuario').value.trim();
            const clave = document.getElementById('clave').value.trim();
            const cedula = document.getElementById('cedula').value.trim();
            const rol = document.getElementById('id_rol').value;

            // Validaciones básicas
            if (!nombre || !usuario || !clave || !cedula || !rol) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Campos Incompletos',
                    text: 'Por favor, completa todos los campos requeridos.',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#2196F3'
                });
                return;
            }

            // Validar longitud de contraseña
            if (clave.length < 6) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Contraseña Débil',
                    text: 'La contraseña debe tener al menos 6 caracteres.',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#2196F3'
                });
                return;
            }

            // Mostrar loading
            const submitBtn = document.querySelector('.btn-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creando usuario...';
        });
    </script>
</body>

</html>