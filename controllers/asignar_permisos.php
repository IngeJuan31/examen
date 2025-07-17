<?php
require_once '../config/db.php';


// Cargar usuarios
$usuarios = $pdo->query("SELECT id_admin, nombre, usuario FROM usuarios_admin ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Cargar permisos
$permisos = $pdo->query("SELECT id_permiso, nombre_permiso, descripcion FROM permisos ORDER BY nombre_permiso")->fetchAll(PDO::FETCH_ASSOC);

// Función para obtener permisos actuales de un usuario
function obtenerPermisosUsuario($pdo, $id_admin) {
    $stmt = $pdo->prepare("SELECT id_permiso FROM usuarios_permisos WHERE id_admin = :id_admin");
    $stmt->execute([':id_admin' => $id_admin]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Procesar envío del formulario
$mensaje = '';
$tipo_mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_admin = (int)$_POST['id_admin'];
    $permisosSeleccionados = $_POST['permisos'] ?? [];

    try {
        // Eliminar permisos anteriores
        $pdo->prepare("DELETE FROM usuarios_permisos WHERE id_admin = :id_admin")->execute([':id_admin' => $id_admin]);

        // Insertar nuevos permisos
        $stmt = $pdo->prepare("INSERT INTO usuarios_permisos (id_admin, id_permiso) VALUES (:id_admin, :id_permiso)");
        foreach ($permisosSeleccionados as $id_permiso) {
            $stmt->execute([
                ':id_admin' => $id_admin,
                ':id_permiso' => $id_permiso
            ]);
        }

        $mensaje = "Permisos asignados exitosamente al usuario seleccionado.";
        $tipo_mensaje = "success";
    } catch (Exception $e) {
        $mensaje = "Error al asignar permisos: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// Endpoint AJAX para cargar permisos del usuario
if (isset($_GET['cargar_permisos']) && isset($_GET['id_admin'])) {
    $id_admin = (int)$_GET['id_admin'];
    $permisos_usuario = obtenerPermisosUsuario($pdo, $id_admin);
    
    // Obtener nombre del usuario
    $stmt = $pdo->prepare("SELECT nombre FROM usuarios_admin WHERE id_admin = :id_admin");
    $stmt->execute([':id_admin' => $id_admin]);
    $nombre_usuario = $stmt->fetchColumn();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'permisos' => $permisos_usuario,
        'nombre_usuario' => $nombre_usuario,
        'total_permisos' => count($permisos_usuario)
    ]);
    exit;
}

?>
<?php if (!empty($recargarPermisos) && $recargarPermisos): ?>
<script>
fetch('/examen_ingreso/controllers/recargar_permisos.php')
    .then(res => res.json())
    .then(data => {
        if (data.status === 'ok') {
            Swal.fire('✅ Permisos actualizados', 'Tus permisos fueron actualizados correctamente.', 'success')
                .then(() => {
                    location.reload();
                });
        } else {
            console.error('No se pudieron recargar los permisos:', data.msg);
        }
    });
</script>
<?php endif; ?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INCATEC - Asignar Permisos</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
            --sombra-suave: 0 2px 8px rgba(0,0,0,0.1);
            --sombra-hover: 0 4px 15px rgba(0,0,0,0.15);
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
            max-width: 1000px;
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
        }

        .nav-link:hover {
            background: var(--azul-incatec);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--sombra-hover);
        }

        .main-content {
            background: white;
            border-radius: 16px;
            box-shadow: var(--sombra-suave);
            overflow: hidden;
        }

        .form-container {
            padding: 40px;
        }

        .form-section {
            margin-bottom: 40px;
        }

        .form-section h3 {
            color: var(--azul-incatec);
            font-size: 1.5rem;
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

        .form-select:focus {
            outline: none;
            border-color: var(--azul-incatec);
            background: white;
            box-shadow: 0 0 0 4px rgba(33, 150, 243, 0.1);
        }

        .permisos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .permiso-card {
            background: var(--gris-suave);
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }

        .permiso-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--sombra-hover);
        }

        .permiso-card.selected {
            background: #e3f2fd;
            border-color: var(--azul-incatec);
            box-shadow: 0 0 0 4px rgba(33, 150, 243, 0.1);
        }

        .permiso-checkbox {
            display: none;
        }

        .permiso-label {
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: pointer;
            font-weight: 600;
            color: var(--gris-oscuro);
            font-size: 1rem;
        }

        .permiso-icon {
            width: 50px;
            height: 50px;
            background: var(--azul-incatec);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .permiso-card.selected .permiso-icon {
            background: var(--verde-success);
            transform: scale(1.1);
        }

        .permiso-info {
            flex: 1;
        }

        .permiso-name {
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--gris-oscuro);
        }

        .permiso-description {
            font-size: 0.9rem;
            color: #666;
            font-weight: 400;
        }

        .check-indicator {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .permiso-card.selected .check-indicator {
            background: var(--verde-success);
            color: white;
        }

        .actions-container {
            background: var(--gris-suave);
            padding: 30px 40px;
            border-top: 1px solid #e1e5e9;
            display: flex;
            gap: 15px;
            justify-content: space-between;
            align-items: center;
        }

        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--sombra-hover);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: var(--sombra-hover);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--verde-success), #388e3c);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }

        .stats-info {
            display: flex;
            gap: 20px;
            align-items: center;
            font-size: 0.9rem;
            color: #666;
        }

        .stat-badge {
            background: var(--azul-incatec);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
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
            
            .permisos-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .stats-info {
                flex-direction: column;
                gap: 10px;
            }
        }

        /* Animaciones */
        .main-content {
            animation: slideUp 0.6s ease-out;
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

        .permiso-card {
            animation: fadeIn 0.6s ease-out;
            animation-fill-mode: both;
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
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1>
                    <i class="fas fa-shield-alt"></i>
                    Gestión de Permisos
                </h1>
                <p>Asignar permisos específicos a usuarios administrativos</p>
            </div>
        </div>

        <!-- Navegación con botón Volver -->
        <div class="nav-links">
            <button onclick="history.back()" class="nav-link nav-back-btn">
                <i class="fas fa-arrow-left"></i>
                Volver
            </button>
            <a href="/examen_ingreso/admin/index.php" class="nav-link">
                <i class="fas fa-home"></i>
                Dashboard
            </a>


        </div>

        <!-- Contenido Principal -->
        <div class="main-content">
            <form method="POST" id="permisosForm" class="form-container">
                <!-- Selección de Usuario -->
                <div class="form-section">
                    <h3>
                        <i class="fas fa-user"></i>
                        Seleccionar Usuario
                    </h3>
                    <div class="form-group">
                        <label for="id_admin">
                            <i class="fas fa-user-tie"></i>
                            Usuario Administrador
                        </label>
                        <select name="id_admin" id="id_admin" class="form-select" required>
                            <option value="">-- Selecciona un usuario --</option>
                            <?php foreach ($usuarios as $u): ?>
                                <option value="<?= $u['id_admin'] ?>" 
                                        data-nombre="<?= htmlspecialchars($u['nombre']) ?>"
                                        data-usuario="<?= htmlspecialchars($u['usuario']) ?>">
                                    <?= htmlspecialchars($u['nombre']) ?> (<?= htmlspecialchars($u['usuario']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Selección de Permisos -->
                <div class="form-section">
                    <h3>
                        <i class="fas fa-key"></i>
                        Permisos Disponibles
                    </h3>
                    <div class="permisos-grid" id="permisosGrid">
                        <?php 
                        $iconos_permisos = [
                            'crear_usuarios' => 'fas fa-user-plus',
                            'editar_usuarios' => 'fas fa-user-edit',
                            'eliminar_usuarios' => 'fas fa-user-minus',
                            'ver_reportes' => 'fas fa-chart-bar',
                            'gestionar_permisos' => 'fas fa-shield-alt',
                            'acceder_configuracion' => 'fas fa-cog',
                            'exportar_datos' => 'fas fa-download',
                            'gestionar_competencias' => 'fas fa-book',
                            'gestionar_preguntas' => 'fas fa-question-circle',
                            'gestionar_participantes' => 'fas fa-users'
                        ];
                        ?>
                        <?php foreach ($permisos as $index => $p): ?>
                            <div class="permiso-card" data-permiso="<?= $p['id_permiso'] ?>" 
                                 style="animation-delay: <?= $index * 0.1 ?>s">
                                <input type="checkbox" 
                                       id="permiso_<?= $p['id_permiso'] ?>" 
                                       name="permisos[]" 
                                       value="<?= $p['id_permiso'] ?>" 
                                       class="permiso-checkbox">
                                <label for="permiso_<?= $p['id_permiso'] ?>" class="permiso-label">
                                    <div class="permiso-icon">
                                        <i class="<?= $iconos_permisos[$p['nombre_permiso']] ?? 'fas fa-key' ?>"></i>
                                    </div>
                                    <div class="permiso-info">
                                        <div class="permiso-name"><?= htmlspecialchars($p['nombre_permiso']) ?></div>
                                        <div class="permiso-description">
                                            <?= htmlspecialchars($p['descripcion']) ?: 'Sin descripción disponible' ?>
                                        </div>
                                    </div>
                                </label>
                                <div class="check-indicator">
                                    <i class="fas fa-check"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Acciones -->
                <div class="actions-container">
                    <div class="stats-info">
                        <div class="stat-badge" id="permisosCount">0 permisos seleccionados</div>
                        <span>de <?= count($permisos) ?> disponibles</span>
                    </div>
                    <div style="display: flex; gap: 15px;">
                        <button type="button" class="btn btn-secondary" onclick="limpiarSeleccion()">
                            <i class="fas fa-eraser"></i>
                            Limpiar Todo
                        </button>
                        <button type="button" class="btn btn-success" onclick="seleccionarTodos()">
                            <i class="fas fa-check-double"></i>
                            Seleccionar Todos
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Asignar Permisos
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Manejo de selección de permisos
        document.addEventListener('DOMContentLoaded', function() {
            const permisoCards = document.querySelectorAll('.permiso-card');
            const permisosCount = document.getElementById('permisosCount');
            const userSelect = document.getElementById('id_admin');
            
            // Actualizar contador de permisos
            function actualizarContador() {
                const seleccionados = document.querySelectorAll('.permiso-checkbox:checked').length;
                permisosCount.textContent = `${seleccionados} permisos seleccionados`;
            }
            
            // Manejar clics en las tarjetas
            permisoCards.forEach(card => {
                card.addEventListener('click', function() {
                    const checkbox = this.querySelector('.permiso-checkbox');
                    checkbox.checked = !checkbox.checked;
                    
                    if (checkbox.checked) {
                        this.classList.add('selected');
                    } else {
                        this.classList.remove('selected');
                    }
                    
                    actualizarContador();
                });
            });
            
            // Cargar permisos actuales cuando se selecciona un usuario
            userSelect.addEventListener('change', function() {
                if (this.value) {
                    cargarPermisosUsuario(this.value);
                } else {
                    limpiarSeleccion();
                }
            });
            
            // Función para cargar permisos del usuario
            function cargarPermisosUsuario(idAdmin) {
                fetch(`?cargar_permisos=1&id_admin=${idAdmin}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            limpiarSeleccion();
                            data.permisos.forEach(permiso => {
                                const checkbox = document.getElementById(`permiso_${permiso}`);
                                if (checkbox) {
                                    checkbox.checked = true;
                                    checkbox.closest('.permiso-card').classList.add('selected');
                                }
                            });
                            permisosCount.textContent = `${data.total_permisos} permisos seleccionados`;
                        } else {
                            console.error('Error al cargar permisos del usuario:', data.msg);
                        }
                    })
                    .catch(error => console.error('Error en la petición AJAX:', error));
            }
            
            actualizarContador();
        });
        
        // Función para limpiar selección
        function limpiarSeleccion() {
            document.querySelectorAll('.permiso-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            document.querySelectorAll('.permiso-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.getElementById('permisosCount').textContent = '0 permisos seleccionados';
        }
        
        // Función para seleccionar todos
        function seleccionarTodos() {
            document.querySelectorAll('.permiso-checkbox').forEach(checkbox => {
                checkbox.checked = true;
            });
            document.querySelectorAll('.permiso-card').forEach(card => {
                card.classList.add('selected');
            });
            document.getElementById('permisosCount').textContent = '<?= count($permisos) ?> permisos seleccionados';
        }
        
        // Validación del formulario
        document.getElementById('permisosForm').addEventListener('submit', function(e) {
            const usuario = document.getElementById('id_admin').value;
            const permisosSeleccionados = document.querySelectorAll('.permiso-checkbox:checked');
            
            if (!usuario) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Usuario Requerido',
                    text: 'Por favor selecciona un usuario para asignar permisos.',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#2196F3'
                });
                return;
            }
            
            if (permisosSeleccionados.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Permisos Requeridos',
                    text: 'Por favor selecciona al menos un permiso para asignar.',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#2196F3'
                });
                return;
            }
            
            // Mostrar confirmación
            e.preventDefault();
            const nombreUsuario = document.getElementById('id_admin').selectedOptions[0].dataset.nombre;
            
            Swal.fire({
                title: '¿Confirmar Asignación?',
                html: `
                    <div style="text-align: center; padding: 20px;">
                        <div style="background: #e3f2fd; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                            <h3 style="color: #1976d2; margin-bottom: 10px;">
                                <i class="fas fa-user"></i> ${nombreUsuario}
                            </h3>
                            <p style="color: #666; margin-bottom: 0;">
                                Se asignarán <strong>${permisosSeleccionados.length} permisos</strong> a este usuario
                            </p>
                        </div>
                        <p style="color: #666; font-size: 0.9rem;">
                            Los permisos anteriores serán reemplazados por los nuevos seleccionados.
                        </p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check"></i> Confirmar',
                cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
                confirmButtonColor: '#4CAF50',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Mostrar loading
                    Swal.fire({
                        title: 'Asignando Permisos...',
                        text: 'Por favor espera mientras se procesan los cambios.',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Enviar formulario
                    this.submit();
                }
            });
        });
        
        // Mostrar mensajes de PHP
        <?php if ($mensaje && $tipo_mensaje): ?>
            document.addEventListener('DOMContentLoaded', function() {
                <?php if ($tipo_mensaje === 'success'): ?>
                    Swal.fire({
                        icon: 'success',
                        title: '¡Permisos Asignados!',
                        text: '<?= $mensaje ?>',
                        confirmButtonText: 'Excelente',
                        confirmButtonColor: '#4CAF50',
                        timer: 4000,
                        timerProgressBar: true
                    });
                <?php elseif ($tipo_mensaje === 'error'): ?>
                    Swal.fire({
                        icon: 'error',
                        title: 'Error al Asignar',
                        text: '<?= $mensaje ?>',
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#f44336'
                    });
                <?php endif; ?>
            });
        <?php endif; ?>
    </script>
</body>
</html>

