<?php
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../controllers/verificar_sesion.php';
require_once '../controllers/permisos.php';
verificarPermiso('PARTICIPANTES'); // Cambia el permiso según la vista

$alerta = null;
$participante_editar = null;

// Asignar examen por dificultad
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_examen'])) {
    $id_participante = $_POST['id_participante'];
    $nivel_dificultad = $_POST['nivel_dificultad'];
    
    try {
        // Verificar si ya tiene examen asignado
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM asignaciones_examen WHERE id_participante = ?");
        $stmt_check->execute([$id_participante]);
        $ya_tiene_examen = $stmt_check->fetchColumn() > 0;
        
        if ($ya_tiene_examen) {
            // Actualizar nivel existente
            $stmt = $pdo->prepare("UPDATE asignaciones_examen SET nivel_dificultad = ?, fecha_asignacion = CURRENT_TIMESTAMP WHERE id_participante = ?");
            $stmt->execute([$nivel_dificultad, $id_participante]);
            $alerta = ['tipo' => 'success', 'mensaje' => 'Nivel de examen actualizado correctamente a: ' . ucfirst($nivel_dificultad)];
        } else {
            // Crear nueva asignación
            $stmt = $pdo->prepare("INSERT INTO asignaciones_examen (id_participante, nivel_dificultad, fecha_asignacion) VALUES (?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$id_participante, $nivel_dificultad]);
            $alerta = ['tipo' => 'success', 'mensaje' => 'Examen asignado correctamente con nivel: ' . ucfirst($nivel_dificultad)];
        }
    } catch (PDOException $e) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'Error al asignar examen: ' . $e->getMessage()];
    }
}

// Crear participante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_participante'])) {
    $nombre = trim($_POST['nombre']);
    $correo = isset($_POST['correo']) ? strtolower(trim($_POST['correo'])) : '';
    $identificacion = trim($_POST['identificacion']);
    
    // ✅ VALIDACIÓN: Verificar que todos los campos requeridos estén presentes
    if (empty($nombre)) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'El nombre es requerido'];
    } elseif (empty($correo)) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'El correo electrónico es requerido'];
    } elseif (empty($identificacion)) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'La identificación es requerida'];
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'El correo electrónico no tiene un formato válido'];
    } else {
        // ✅ DATOS ADICIONALES: Solo usar si están disponibles
        $primer_nombre = $_POST['primer_nombre'] ?? '';
        $segundo_nombre = $_POST['segundo_nombre'] ?? '';
        $primer_apellido = $_POST['primer_apellido'] ?? '';
        $segundo_apellido = $_POST['segundo_apellido'] ?? '';
        $programa = $_POST['programa'] ?? '';
        $semestre = $_POST['semestre'] ?? '';
        $jornada = $_POST['jornada'] ?? '';
        $tipo_documento = $_POST['tipo_documento'] ?? '';
        $origen_datos = $_POST['origen_datos'] ?? 'manual';

        try {
            // ✅ VERIFICAR Y CORREGIR LLAVES FORÁNEAS
            $stmt_check_table = $pdo->prepare("SELECT column_name FROM information_schema.columns 
                                               WHERE table_name = 'participantes' 
                                               AND table_schema = 'public'
                                               ORDER BY column_name");
            $stmt_check_table->execute();
            $available_columns = $stmt_check_table->fetchAll(PDO::FETCH_COLUMN);
            
            // Verificar qué columnas existen
            $has_usuario = in_array('usuario', $available_columns);
            $has_clave = in_array('clave', $available_columns);
            $has_primer_nombre = in_array('primer_nombre', $available_columns);
            $has_programa = in_array('programa', $available_columns);
            $has_origen_datos = in_array('origen_datos', $available_columns);
            $has_tipo_documento = in_array('tipo_documento', $available_columns);
            
            // ✅ NUEVA VERIFICACIÓN: Manejar tipos de identificación
            if ($has_tipo_documento && !empty($tipo_documento)) {
                // Verificar si existe la tabla tipo_identificacion
                $stmt_check_tipo = $pdo->prepare("SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = 'tipo_identificacion'
                )");
                $stmt_check_tipo->execute();
                $tabla_tipo_existe = $stmt_check_tipo->fetchColumn();
                
                if ($tabla_tipo_existe) {
                    // Verificar si el tipo de documento existe
                    $stmt_verificar_tipo = $pdo->prepare("SELECT id FROM tipo_identificacion WHERE nombre = ? OR codigo = ?");
                    $stmt_verificar_tipo->execute([$tipo_documento, $tipo_documento]);
                    $tipo_valido = $stmt_verificar_tipo->fetch();
                    
                    if (!$tipo_valido) {
                        // Crear el tipo de documento si no existe
                        $stmt_crear_tipo = $pdo->prepare("INSERT INTO tipo_identificacion (nombre, codigo, descripcion) VALUES (?, ?, ?) RETURNING id");
                        $stmt_crear_tipo->execute([
                            $tipo_documento,
                            strtoupper(substr($tipo_documento, 0, 3)),
                            'Tipo de documento creado automáticamente'
                        ]);
                        $tipo_valido = $stmt_crear_tipo->fetch();
                    }
                    
                    // Usar el ID del tipo de documento
                    $tipo_documento_id = $tipo_valido['id'];
                } else {
                    // Si no existe la tabla, no usar tipo_documento
                    $tipo_documento = '';
                    $has_tipo_documento = false;
                }
            }
            
            // Construir query dinámicamente según las columnas disponibles
            $campos = ['nombre', 'correo', 'identificacion'];
            $valores = [$nombre, $correo, $identificacion];
            $placeholders = ['?', '?', '?'];
            
            // Agregar usuario y clave si existen las columnas
            if ($has_usuario && $has_clave) {
                $usuario = $identificacion;
                $clave = password_hash($identificacion, PASSWORD_DEFAULT);
                $campos[] = 'usuario';
                $campos[] = 'clave';
                $valores[] = $usuario;
                $valores[] = $clave;
                $placeholders[] = '?';
                $placeholders[] = '?';
            }
            
            // Agregar campos adicionales solo si existen
            if ($has_primer_nombre && !empty($primer_nombre)) {
                $campos[] = 'primer_nombre';
                $valores[] = $primer_nombre;
                $placeholders[] = '?';
            }
            
            if (in_array('segundo_nombre', $available_columns) && !empty($segundo_nombre)) {
                $campos[] = 'segundo_nombre';
                $valores[] = $segundo_nombre;
                $placeholders[] = '?';
            }
            
            if (in_array('primer_apellido', $available_columns) && !empty($primer_apellido)) {
                $campos[] = 'primer_apellido';
                $valores[] = $primer_apellido;
                $placeholders[] = '?';
            }
            
            if (in_array('segundo_apellido', $available_columns) && !empty($segundo_apellido)) {
                $campos[] = 'segundo_apellido';
                $valores[] = $segundo_apellido;
                $placeholders[] = '?';
            }
            
            if ($has_programa && !empty($programa)) {
                $campos[] = 'programa';
                $valores[] = $programa;
                $placeholders[] = '?';
            }
            
            if (in_array('semestre', $available_columns) && !empty($semestre)) {
                $campos[] = 'semestre';
                $valores[] = $semestre;
                $placeholders[] = '?';
            }
            
            if (in_array('jornada', $available_columns) && !empty($jornada)) {
                $campos[] = 'jornada';
                $valores[] = $jornada;
                $placeholders[] = '?';
            }
            
            // ✅ AGREGAR TIPO_DOCUMENTO SOLO SI ES VÁLIDO
            if ($has_tipo_documento && isset($tipo_documento_id)) {
                $campos[] = 'tipo_documento';
                $valores[] = $tipo_documento_id;
                $placeholders[] = '?';
            }
            
            if ($has_origen_datos) {
                $campos[] = 'origen_datos';
                $valores[] = $origen_datos;
                $placeholders[] = '?';
            }
            
            // Construir y ejecutar la consulta
            $sql = "INSERT INTO participantes (" . implode(', ', $campos) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($valores);
            
            // Determinar tipo de alerta según las capacidades de la tabla
            if ($has_usuario && $has_clave) {
                $mensaje_origen = $origen_datos === 'api_incatec' ? ' (datos desde INCATEC)' : '';
                $alerta = [
                    'tipo' => 'success', 
                    'mensaje' => "Participante habilitado correctamente{$mensaje_origen}",
                    'credenciales' => [
                        'usuario' => $usuario,
                        'clave' => $identificacion,
                        'mostrar_credenciales' => true
                    ]
                ];
            } else {
                // Base de datos sin campos usuario/clave
                $alerta = [
                    'tipo' => 'warning', 
                    'mensaje' => 'Participante creado sin credenciales',
                    'sin_credenciales' => true,
                    'nombre_participante' => $nombre,
                    'columnas_faltantes' => !$has_usuario ? 'usuario, clave' : 'clave'
                ];
            }
            
        } catch (PDOException $e) {
            // ✅ MEJORAR MANEJO DE ERRORES ESPECÍFICOS PARA FOREIGN KEY
            $error_code = $e->getCode();
            $error_message = $e->getMessage();
            
            if ($error_code === '23503' || strpos($error_message, 'Foreign key violation') !== false || strpos($error_message, 'llave foránea') !== false) {
                $alerta = [
                    'tipo' => 'error', 
                    'mensaje' => 'Error de configuración de base de datos',
                    'detalle_error' => 'Problema con llaves foráneas - ejecute las migraciones',
                    'error_tecnico' => true,
                    'solucion' => 'Vaya a Configuración → Migrar Base de Datos y ejecute las migraciones pendientes'
                ];
            } elseif ($error_code === '23505' || strpos($error_message, 'UNIQUE') !== false || strpos($error_message, 'Duplicate') !== false) {
                $alerta = [
                    'tipo' => 'error', 
                    'mensaje' => 'El correo o identificación ya están registrados',
                    'detalle_error' => 'Ya existe un participante con estos datos'
                ];
            } elseif (strpos($error_message, 'no existe la columna') !== false || strpos($error_message, "Unknown column") !== false) {
                $alerta = [
                    'tipo' => 'error', 
                    'mensaje' => 'La tabla de participantes necesita actualización',
                    'error_tecnico' => true,
                    'detalle_error' => 'Columnas faltantes en la base de datos',
                    'solucion' => 'Ejecute las migraciones de base de datos'
                ];
            } else {
                $alerta = [
                    'tipo' => 'error', 
                    'mensaje' => 'Error inesperado al registrar participante',
                    'error_tecnico' => true,
                    'detalle_error' => $error_message,
                    'solucion' => 'Revise la configuración de la base de datos'
                ];
            }
        }
    }
}

// Editar participante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_participante'])) {
    $id_participante = $_POST['id_participante'];
    $nombre = trim($_POST['nombre']);
    $correo = isset($_POST['correo']) ? strtolower(trim($_POST['correo'])) : '';
    $identificacion = trim($_POST['identificacion']);
    
    // ✅ VALIDACIÓN: Verificar que todos los campos requeridos estén presentes
    if (empty($nombre)) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'El nombre es requerido'];
    } elseif (empty($correo)) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'El correo electrónico es requerido'];
    } elseif (empty($identificacion)) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'La identificación es requerida'];
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $alerta = ['tipo' => 'error', 'mensaje' => 'El correo electrónico no tiene un formato válido'];
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE participantes SET nombre = ?, correo = ?, identificacion = ? WHERE id_participante = ?");
            $stmt->execute([$nombre, $correo, $identificacion, $id_participante]);
            $alerta = ['tipo' => 'success', 'mensaje' => 'Participante actualizado correctamente'];
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' || strpos($e->getMessage(), 'UNIQUE') !== false) {
                $alerta = ['tipo' => 'error', 'mensaje' => 'El correo o identificación ya están registrados'];
            } else {
                $alerta = ['tipo' => 'error', 'mensaje' => 'Error al actualizar: ' . $e->getMessage()];
            }
        }
    }
}

// Eliminar participante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_participante'])) {
    $id_participante = $_POST['id_participante'];
    
    try {
        $pdo->beginTransaction();
        
        // Verificar si el participante tiene respuestas
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM respuestas WHERE id_participante = ?");
        $stmt_check->execute([$id_participante]);
        $tiene_respuestas = $stmt_check->fetchColumn() > 0;
        
        if ($tiene_respuestas) {
            throw new Exception("No se puede eliminar este participante porque ya ha respondido preguntas del examen.");
        }
        
        $stmt = $pdo->prepare("DELETE FROM participantes WHERE id_participante = ?");
        $stmt->execute([$id_participante]);
        
        $pdo->commit();
        $alerta = ['tipo' => 'success', 'mensaje' => 'Participante eliminado exitosamente'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $alerta = ['tipo' => 'error', 'mensaje' => $e->getMessage()];
    }
}

// Obtener participante para editar
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM participantes WHERE id_participante = ?");
    $stmt->execute([$_GET['editar']]);
    $participante_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtener participantes con estadísticas
try {
    // Verificar si los campos usuario y clave existen en la tabla
    $stmt_check = $pdo->prepare("SELECT column_name FROM information_schema.columns 
                                WHERE table_name = 'participantes' 
                                AND table_schema = 'public' 
                                AND column_name IN ('usuario', 'clave')");
    $stmt_check->execute();
    $existing_columns = $stmt_check->fetchAll(PDO::FETCH_COLUMN);
    
    $usuario_exists = in_array('usuario', $existing_columns);
    $clave_exists = in_array('clave', $existing_columns);
    
    if ($usuario_exists && $clave_exists) {
        $participantes = $pdo->query("
            SELECT p.id_participante, p.nombre, p.correo, p.identificacion, p.fecha_registro,
            COALESCE(p.usuario, p.identificacion) AS usuario,
            CASE WHEN p.clave IS NOT NULL AND p.clave != '' THEN 'Sí' ELSE 'No' END AS tiene_clave,
            (SELECT COUNT(*) FROM respuestas r WHERE r.id_participante = p.id_participante) AS num_respuestas,
            (SELECT MAX(r.fecha_respuesta) FROM respuestas r WHERE r.id_participante = p.id_participante) AS ultima_actividad,
            COALESCE(ae.nivel_dificultad, 'sin-asignar') AS nivel_examen,
            ae.fecha_asignacion
            FROM participantes p 
            LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
            ORDER BY p.fecha_registro DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Versión compatible para base de datos sin campos usuario/clave
        $participantes = $pdo->query("
            SELECT p.id_participante, p.nombre, p.correo, p.identificacion, p.fecha_registro,
            p.identificacion AS usuario,
            'Pendiente migración' AS tiene_clave,
            (SELECT COUNT(*) FROM respuestas r WHERE r.id_participante = p.id_participante) AS num_respuestas,
            (SELECT MAX(r.fecha_respuesta) FROM respuestas r WHERE r.id_participante = p.id_participante) AS ultima_actividad,
            'sin-asignar' AS nivel_examen,
            NULL AS fecha_asignacion
            FROM participantes p 
            ORDER BY p.fecha_registro DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Fallback en caso de error
    $participantes = $pdo->query("
        SELECT p.id_participante, p.nombre, p.correo, p.identificacion, p.fecha_registro,
        p.identificacion AS usuario,
        'Error verificando BD' AS tiene_clave,
        0 AS num_respuestas,
        NULL AS ultima_actividad,
        'sin-asignar' AS nivel_examen,
        NULL AS fecha_asignacion
        FROM participantes p 
        ORDER BY p.fecha_registro DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<?php if ($alerta): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($alerta['sin_credenciales']) && $alerta['sin_credenciales']): ?>
        // SweetAlert específico para participante sin credenciales
        Swal.fire({
            icon: 'warning',
            title: '⚠️ Participante Creado sin Credenciales',
            html: `
                <div style="text-align: left; margin: 20px 0;">
                    <p><strong>Participante:</strong> <?= addslashes($alerta['nombre_participante'] ?? '') ?></p>
                    
                    <div style="background: #fff3cd; padding: 15px; border-radius: 8px
                        <p style="margin: 0; color: #856404;"><strong>🚨 Sistema sin credenciales</strong></p>
                        <p style="margin: 10px 0 0 0; color: #856404;">La base de datos no tiene configurados los campos: <code><?= $alerta['columnas_faltantes'] ?? 'usuario, clave' ?></code></p>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;">
                        <h4 style="margin: 0 0 10px 0; color: #495057;">📋 Para habilitar credenciales:</h4>
                        <ol style="margin: 0; padding-left: 20px; color: #495057; font-size: 13px;">
                            <li>Vaya a <strong>Configuración → Migrar Base de Datos</strong></li>
                            <li>Ejecute las migraciones pendientes</li>
                            <li>Los campos usuario/clave se agregarán automáticamente a los participantes existentes</li>
                            <li>Los nuevos participantes tendrán credenciales funcionales</li>
                        </ol>
                    </div>
                    
                    <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; border-left: 4px solid #2196F3; margin: 15px 0;">
                        <p style="margin: 0; color: #1565c0;"><strong>✅ El participante fue creado exitosamente</strong></p>
                        <p style="margin: 10px 0 0 0; color: #1565c0;">Solo necesita migrar la base de datos para habilitar el acceso al examen.</p>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-database"></i> Migrar BD Ahora',
            cancelButtonText: '<i class="fas fa-check"></i> Entendido',
            confirmButtonColor: '#2196F3',
            cancelButtonColor: '#6c757d',
            reverseButtons: true,
            customClass: {
                popup: 'swal-wide'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Redirigir a la página de migración
                window.location.href = '../admin/migracion.php';
            }
        });
        
    <?php elseif (isset($alerta['credenciales']) && $alerta['credenciales']['mostrar_credenciales']): ?>
        // SweetAlert para participante creado con credenciales
        Swal.fire({
            icon: 'success',
            title: '✅ ¡Participante Habilitado!',
            html: `
                <div style="text-align: left; margin: 20px 0;">
                    <div style="background: #d4edda; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745; margin: 15px 0;">
                        <p style="margin: 0; color: #155724;"><strong>🎉 Registro exitoso</strong></p>
                        <p style="margin: 10px 0 0 0; color: #155724;">El participante puede acceder al examen con estas credenciales:</p>
                    </div>
                    
                    <div style="background: #f8f9ff; padding: 15px; border-radius: 8px; border: 1px solid #e3e8ff; margin: 15px 0;">
                        <h4 style="margin: 0 0 15px 0; color: #4c63d2;">🔐 Credenciales de Acceso</h4>
                        
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px; padding: 8px; background: white; border-radius: 4px; border: 1px solid #d4dbff;">
                            <strong style="min-width: 60px; font-size: 12px; color: #6c757d;">Usuario:</strong>
                            <code style="flex: 1; background: #f0f3ff; padding: 4px 8px; border-radius: 3px; color: #4c63d2; font-weight: 600;"><?= addslashes($alerta['credenciales']['usuario'] ?? '') ?></code>
                            <button onclick="copiarTexto('<?= addslashes($alerta['credenciales']['usuario'] ?? '') ?>')" style="background: #4c63d2; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer; font-size: 10px;">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px; padding: 8px; background: white; border-radius: 4px; border: 1px solid #d4dbff;">
                            <strong style="min-width: 60px; font-size: 12px; color: #6c757d;">Clave:</strong>
                            <code style="flex: 1; background: #f0f3ff; padding: 4px 8px; border-radius: 3px; color: #4c63d2; font-weight: 600;"><?= addslashes($alerta['credenciales']['clave'] ?? '') ?></code>
                            <button onclick="copiarTexto('<?= addslashes($alerta['credenciales']['clave'] ?? '') ?>')" style="background: #4c63d2; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer; font-size: 10px;">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        
                        <p style="margin: 10px 0 0 0; font-size: 11px; color: #6c757d; font-style: italic;">
                            <i class="fas fa-info-circle"></i> 
                            La clave es la misma identificación del participante
                        </p>
                    </div>
                    
                    <div style="background: #e8f5e8; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745; margin: 15px 0;">
                        <p style="margin: 0; color: #155724;"><strong>📝 Siguiente paso:</strong></p>
                        <p style="margin: 10px 0 0 0; color: #155724;">Asigna un nivel de examen al participante usando el botón "Asignar Examen" en su tarjeta.</p>
                    </div>
                </div>
            `,
            confirmButtonText: '<i class="fas fa-check"></i> ¡Perfecto!',
            confirmButtonColor: '#28a745',
            allowOutsideClick: false,
            customClass: {
                popup: 'swal-wide'
            }
        });
        
    <?php elseif (isset($alerta['error_tecnico']) && $alerta['error_tecnico']): ?>
        // SweetAlert específico para errores técnicos detallados
        Swal.fire({
            icon: 'error',
            title: '🔧 Error Técnico de Base de Datos',
            html: `
                <div style="text-align: left; margin: 20px 0;">
                    <div style="background: #f8d7da; padding: 15px; border-radius: 8px; border-left: 4px solid #dc3545; margin: 15px 0;">
                        <p style="margin: 0; color: #721c24;"><strong>❌ Error Principal:</strong></p>
                        <p style="margin: 10px 0 0 0; color: #721c24;"><?= addslashes($alerta['mensaje']) ?></p>
                    </div>
                    
                    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 15px 0;">
                        <p style="margin: 0; color: #856404;"><strong>🔧 Solución Recomendada:</strong></p>
                        <p style="margin: 10px 0 0 0; color: #856404;"><?= addslashes($alerta['solucion'] ?? 'Contacte al administrador del sistema') ?></p>
                    </div>
                    
                    <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; border-left: 4px solid #2196F3; margin: 15px 0;">
                        <p style="margin: 0; color: #1565c0;"><strong>📋 Pasos a seguir:</strong></p>
                        <ol style="margin: 10px 0 0 20px; color: #1565c0; font-size: 13px;">
                            <li>Vaya a <strong>Configuración → Migrar Base de Datos</strong></li>
                            <li>Ejecute todas las migraciones pendientes</li>
                            <li>Esto corregirá automáticamente la estructura</li>
                            <li>Intente crear el participante nuevamente</li>
                        </ol>
                    </div>
                    
                    <details style="margin: 15px 0;">
                        <summary style="color: #6c757d; cursor: pointer; font-size: 12px; padding: 8px; background: #f8f9fa; border-radius: 4px;">
                            🔍 Ver detalles técnicos del error
                        </summary>
                        <div style="background: #f1f3f4; padding: 12px; border-radius: 4px; margin-top: 8px; border: 1px solid #dee2e6;">
                            <p style="margin: 0; font-size: 11px; color: #495057;"><strong>Tipo:</strong> <?= addslashes($alerta['detalle_error'] ?? 'Error general') ?></p>
                            <?php if (isset($alerta['detalle_error']) && strlen($alerta['detalle_error']) > 50): ?>
                                <div style="margin-top: 8px; padding: 8px; background: #ffffff; border-radius: 3px; border: 1px solid #ced4da;">
                                    <code style="font-size: 10px; color: #e83e8c; word-break: break-all;"><?= addslashes($alerta['detalle_error']) ?></code>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-database"></i> Ir a Migrar BD',
            cancelButtonText: '<i class="fas fa-times"></i> Cerrar',
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#6c757d',
            reverseButtons: true,
            customClass: {
                popup: 'swal-wide'
            },
            width: '650px'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '../admin/migracion.php';
            }
        });
        
    <?php else: ?>
        // SweetAlert normal para otros casos
        Swal.fire({
            icon: '<?= $alerta['tipo'] === 'success' ? 'success' : ($alerta['tipo'] === 'warning' ? 'warning' : 'error') ?>',
            title: '<?= $alerta['tipo'] === 'success' ? '¡Éxito!' : ($alerta['tipo'] === 'warning' ? '¡Atención!' : '¡Error!') ?>',
            text: '<?= addslashes($alerta['mensaje']) ?>',
            <?php if (isset($alerta['detalle_error'])): ?>
                footer: '<small style="color: #6c757d;">💡 Tip: <?= addslashes($alerta['detalle_error']) ?></small>',
            <?php endif; ?>
            timer: <?= $alerta['tipo'] === 'error' ? '0' : '4000' ?>,
            showConfirmButton: true,
            confirmButtonText: 'Entendido',
            confirmButtonColor: '<?= $alerta['tipo'] === 'success' ? '#28a745' : ($alerta['tipo'] === 'warning' ? '#ffc107' : '#dc3545') ?>',
            toast: false,
            position: 'center'
        });
    <?php endif; ?>
});
</script>
<?php endif; ?>

<div class="container">
    <!-- Sección para crear/editar participante -->
    <div class="create-section">
        <h2>
            <i class="fas <?= $participante_editar ? 'fa-user-edit' : 'fa-user-plus' ?>"></i> 
            <?= $participante_editar ? 'Editar Participante' : 'Habilitar Nuevo Participante' ?>
        </h2>
        
        <form method="POST" class="form-card" id="formParticipante">
            <?php if ($participante_editar): ?>
                <input type="hidden" name="editar_participante" value="1">
                <input type="hidden" name="id_participante" value="<?= $participante_editar['id_participante'] ?>">
                
                <div class="edit-notice">
                    <i class="fas fa-info-circle"></i>
                    <span>Editando participante: <strong><?= htmlspecialchars($participante_editar['nombre']) ?></strong></span>
                    <a href="participantes.php" class="btn-cancel-edit">
                        <i class="fas fa-times"></i> Cancelar edición
                    </a>
                </div>
            <?php else: ?>
                <input type="hidden" name="crear_participante" value="1">
            <?php endif; ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> Identificación:</label>
                    <div class="input-with-search">
                        <input type="text" 
                               name="identificacion" 
                               id="identificacion"
                               placeholder="Ej: 12345678, C-123456789" 
                               required 
                               class="form-input" 
                               value="<?= $participante_editar ? htmlspecialchars($participante_editar['identificacion']) : '' ?>">
                        <?php if (!$participante_editar): ?>
                            <button type="button" id="btnBuscarIncatec" class="btn-search-api" title="Buscar en SIC INCATEC">
                                <i class="fas fa-search"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                    <small class="form-hint">
                        <?php if (!$participante_editar): ?>
                            <i class="fas fa-lightbulb"></i> Ingresa el documento y presiona <strong>buscar</strong> para autocompletar desde INCATEC
                        <?php else: ?>
                            Cédula, pasaporte o documento de identidad
                        <?php endif; ?>
                    </small>
                    <div id="busquedaResultado" class="search-result"></div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Nombre completo:</label>
                    <input type="text" 
                           name="nombre" 
                           id="nombre"
                           placeholder="Ej: Juan Carlos Pérez González" 
                           required 
                           class="form-input" 
                           value="<?= $participante_editar ? htmlspecialchars($participante_editar['nombre']) : '' ?>">
                    <small class="form-hint">Nombre completo del participante</small>
                </div>
            </div>
            
            <!-- ✅ AGREGAR ESTE CAMPO QUE FALTA -->
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> Correo electrónico:</label>
                <input type="email" 
                       name="correo" 
                       id="correo"
                       placeholder="Ej: juan.perez@estudiante.incatec.edu.co" 
                       required 
                       class="form-input" 
                       value="<?= $participante_editar ? htmlspecialchars($participante_editar['correo']) : '' ?>">
                <small class="form-hint">Correo institucional del participante</small>
            </div>
            
            <!-- Campos adicionales que se mostrarán cuando se encuentre en INCATEC -->
            <div id="camposAdicionales" class="campos-adicionales" style="display: none;">
                <div class="info-incatec">
                    <h4><i class="fas fa-graduation-cap"></i> Información Académica INCATEC</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-book"></i> Programa:</label>
                            <input type="text" id="programa" readonly class="form-input-readonly">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Semestre:</label>
                            <input type="text" id="semestre" readonly class="form-input-readonly">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> Jornada:</label>
                        <input type="text" id="jornada" readonly class="form-input-readonly">
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary btn-large">
                    <i class="fas <?= $participante_editar ? 'fa-save' : 'fa-user-check' ?>"></i> 
                    <?= $participante_editar ? 'Actualizar Participante' : 'Habilitar Participante' ?>
                </button>
                
                <?php if ($participante_editar): ?>
                    <a href="participantes.php" class="btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a crear nuevo
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <hr class="section-divider">

    <!-- Sección de participantes -->
    <div class="participantes-section">
        <div class="section-header">
            <h2><i class="fas fa-users"></i> Participantes Registrados</h2>
            
            <?php if (!empty($participantes)): ?>
                <div class="stats-summary">
                    <div class="stat-item">
                        <i class="fas fa-user"></i>
                        <span><?= count($participantes) ?> participantes</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-clipboard-check"></i>
                        <span><?= count(array_filter($participantes, function($p) { return $p['num_respuestas'] > 0; })) ?> han respondido</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-clock"></i>
                        <span><?= count(array_filter($participantes, function($p) { return $p['num_respuestas'] == 0; })) ?> pendientes</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Filtros y búsqueda -->
        <div class="filter-section">
            <div class="search-group">
                <div class="search-input-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" 
                           id="searchParticipantes" 
                           placeholder="Buscar por nombre, correo, identificación o usuario..." 
                           class="search-input">
                    <button id="clearSearch" class="btn-clear-search" title="Limpiar búsqueda" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="filters-group">
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-filter"></i> Estado:
                    </label>
                    <select id="filterEstado" class="filter-select">
                        <option value="">Todos los estados</option>
                        <option value="activo">✅ Han respondido</option>
                        <option value="pendiente">⏳ Sin respuestas</option>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-clipboard-list"></i> Examen:
                    </label>
                    <select id="filterExamen" class="filter-select">
                        <option value="">Todos los niveles</option>
                        <option value="sin-asignar">❌ Sin asignar</option>
                        <option value="bajo">🟢 Nivel Bajo</option>
                        <option value="medio">🟡 Nivel Medio</option>
                        <option value="alto">🔴 Nivel Alto</option>
                    </select>
                </div>
                
                <button id="clearFilters" class="btn-clear-filters" title="Limpiar todos los filtros">
                    <i class="fas fa-eraser"></i>
                    <span>Limpiar</span>
                </button>
            </div>
        </div>
        
        <div class="participantes-container" id="participantesContainer">
            <?php if (empty($participantes)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <h3>¡Aún no hay participantes!</h3>
                    <p>Habilita el primer participante usando el formulario de arriba</p>
                </div>
            <?php else: ?>
                <?php foreach ($participantes as $p): ?>
                    <div class="participante-card" 
                         data-search="<?= htmlspecialchars(strtolower($p['nombre'] . ' ' . $p['correo'] . ' ' . $p['identificacion'] . ' ' . $p['usuario'])) ?>"
                         data-estado="<?= $p['num_respuestas'] > 0 ? 'activo' : 'pendiente' ?>"
                         data-nivel-examen="<?= htmlspecialchars($p['nivel_examen']) ?>">
                        <div class="participante-header">
                            <div class="participante-info">
                                <h3><i class="fas fa-user"></i> <?= htmlspecialchars($p['nombre']) ?></h3>
                                <div class="participante-stats">
                                    <span class="estado-badge <?= $p['num_respuestas'] > 0 ? 'activo' : 'pendiente' ?>">
                                        <i class="fas <?= $p['num_respuestas'] > 0 ? 'fa-check-circle' : 'fa-clock' ?>"></i>
                                        <?= $p['num_respuestas'] > 0 ? 'Activo' : 'Pendiente' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="participante-content">
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class="fas fa-envelope"></i>
                                    <div>
                                        <label>Correo:</label>
                                        <span><?= htmlspecialchars($p['correo']) ?></span>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-id-card"></i>
                                    <div>
                                        <label>Identificación:</label>
                                        <span><?= htmlspecialchars($p['identificacion']) ?></span>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar-plus"></i>
                                    <div>
                                        <label>Fecha registro:</label>
                                        <span><?= date('d/m/Y H:i', strtotime($p['fecha_registro'])) ?></span>
                                    </div>
                                </div>
                                <?php if ($p['ultima_actividad']): ?>
                                    <div class="info-item">
                                        <i class="fas fa-clock"></i>
                                        <div>
                                            <label>Última actividad:</label>
                                            <span><?= date('d/m/Y H:i', strtotime($p['ultima_actividad'])) ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($p['num_respuestas'] > 0): ?>
                                <div class="activity-info">
                                    <i class="fas fa-chart-line"></i>
                                    <span><?= $p['num_respuestas'] ?> respuestas registradas</span>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Sección de examen asignado -->
                            <div class="exam-section">
                                <h4><i class="fas fa-clipboard-list"></i> Examen Asignado</h4>
                                <div class="exam-assignment">
                                    <?php if ($p['nivel_examen'] === 'sin-asignar'): ?>
                                        <div class="exam-status no-exam">
                                            <i class="fas fa-exclamation-circle"></i>
                                            <span>Sin examen asignado</span>
                                        </div>
                                        <button class="btn-assign-exam" onclick="mostrarAsignarExamen(<?= $p['id_participante'] ?>, '<?= addslashes(htmlspecialchars($p['nombre'])) ?>')">
                                            <i class="fas fa-plus-circle"></i> Asignar Examen
                                        </button>
                                    <?php else: ?>
                                        <div class="exam-level-info">
                                            <div class="exam-level <?= $p['nivel_examen'] ?>">
                                                <i class="fas <?= $p['nivel_examen'] === 'bajo' ? 'fa-circle' : ($p['nivel_examen'] === 'medio' ? 'fa-circle' : 'fa-circle') ?>"></i>
                                                <span class="level-text">Nivel <?= ucfirst($p['nivel_examen']) ?></span>
                                            </div>
                                            <div class="exam-date">
                                                <i class="fas fa-calendar-check"></i>
                                                <span>Asignado: <?= date('d/m/Y H:i', strtotime($p['fecha_asignacion'])) ?></span>
                                            </div>
                                        </div>
                                        <div class="exam-actions">
                                            <button class="btn-change-exam" onclick="mostrarAsignarExamen(<?= $p['id_participante'] ?>, '<?= addslashes(htmlspecialchars($p['nombre'])) ?>', '<?= $p['nivel_examen'] ?>')">
                                                <i class="fas fa-edit"></i> Cambiar Nivel
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Sección de credenciales -->
                            <div class="credentials-section">
                                <h4><i class="fas fa-lock"></i> Credenciales de Acceso</h4>
                                <?php if (isset($p['tiene_clave']) && $p['tiene_clave'] === 'Pendiente migración'): ?>
                                    <div class="migration-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <span>Ejecute la migración de base de datos para habilitar credenciales</span>
                                    </div>
                                <?php elseif (isset($p['tiene_clave']) && $p['tiene_clave'] === 'Error verificando BD'): ?>
                                    <div class="error-warning">
                                        <i class="fas fa-times-circle"></i>
                                        <span>Error verificando estado de la base de datos</span>
                                    </div>
                                <?php else: ?>
                                    <div class="credentials-grid">
                                        <div class="credential-item">
                                            <label>Usuario:</label>
                                            <span class="credential-value"><?= htmlspecialchars($p['usuario']) ?></span>
                                            <button class="btn-copy" onclick="copiarTexto('<?= htmlspecialchars($p['usuario']) ?>')" title="Copiar usuario">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                        <div class="credential-item">
                                            <label>Clave:</label>
                                            <span class="credential-value"><?= htmlspecialchars($p['identificacion']) ?></span>
                                            <button class="btn-copy" onclick="copiarTexto('<?= htmlspecialchars($p['identificacion']) ?>')" title="Copiar clave">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                        <div class="credential-status">
                                            <label>Estado:</label>
                                            <span class="status-badge <?= $p['tiene_clave'] === 'Sí' ? 'status-active' : 'status-pending' ?>">
                                                <i class="fas <?= $p['tiene_clave'] === 'Sí' ? 'fa-check-circle' : 'fa-clock' ?>"></i>
                                                <?= $p['tiene_clave'] === 'Sí' ? 'Configuradas' : 'Pendientes' ?>
                                            </span>
                                        </div>
                                    </div>
                                    <small class="credentials-note">
                                        <i class="fas fa-info-circle"></i> 
                                        La clave es la misma identificación del participante
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="participante-footer">
                            <div class="participant-id">
                                <small>ID: <?= $p['id_participante'] ?></small>
                            </div>
                            
                            <div class="action-buttons">
                                <a href="participantes.php?editar=<?= $p['id_participante'] ?>" 
                                   class="btn-secondary btn-edit">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                
                                <button class="btn-danger btn-delete" 
                                        onclick="confirmarEliminarParticipante(<?= $p['id_participante'] ?>, '<?= addslashes(htmlspecialchars($p['nombre'])) ?>', <?= $p['num_respuestas'] ?>)">
                                    <i class="fas fa-trash-alt"></i> Eliminar
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Búsqueda y filtrado mejorado
let filtroActual = {
    busqueda: '',
    estado: '',
    examen: ''
};

function aplicarFiltros() {
    const participantes = document.querySelectorAll('.participante-card');
    let participantesVisibles = 0;
    
    participantes.forEach(participante => {
        const textoBusqueda = participante.getAttribute('data-search');
        const estado = participante.getAttribute('data-estado');
        const nivelExamen = participante.getAttribute('data-nivel-examen') || 'sin-asignar';
        
        let mostrar = true;
        
        // Filtro de búsqueda por texto
        if (filtroActual.busqueda && !textoBusqueda.includes(filtroActual.busqueda)) {
            mostrar = false;
        }
        
        // Filtro por estado
        if (filtroActual.estado && estado !== filtroActual.estado) {
            mostrar = false;
        }
        
        // Filtro por nivel de examen
        if (filtroActual.examen && nivelExamen !== filtroActual.examen) {
            mostrar = false;
        }
        
        if (mostrar) {
            participante.style.display = 'block';
            participantesVisibles++;
        } else {
            participante.style.display = 'none';
        }
    });
    
    // Mostrar mensaje si no hay resultados
    mostrarMensajeNoResultados(participantesVisibles === 0);
    
    // Actualizar contador de resultados
    actualizarContadorResultados(participantesVisibles);
}

function actualizarContadorResultados(cantidad) {
    let contador = document.getElementById('contadorResultados');
    if (!contador) {
        contador = document.createElement('div');
        contador.id = 'contadorResultados';
        contador.className = 'contador-resultados';
        document.querySelector('.filter-section').appendChild(contador);
    }
    
    const total = document.querySelectorAll('.participante-card').length;
    if (cantidad === total) {
        contador.innerHTML = `<i class="fas fa-users"></i> Mostrando ${total} participantes`;
    } else {
        contador.innerHTML = `<i class="fas fa-filter"></i> Mostrando ${cantidad} de ${total} participantes`;
    }
}

function mostrarMensajeNoResultados(mostrar) {
    let mensajeExistente = document.getElementById('noResultados');
    
    if (mostrar && !mensajeExistente) {
        const mensaje = document.createElement('div');
        mensaje.id = 'noResultados';
        mensaje.className = 'no-results-message';
        mensaje.innerHTML = `
            <div class="no-results-content">
                <i class="fas fa-search"></i>
                <h3>No se encontraron participantes</h3>
                <p>Intenta ajustar los filtros o la búsqueda</p>
                <button onclick="limpiarFiltros()" class="btn-secondary">
                    <i class="fas fa-eraser"></i> Limpiar filtros
                </button>
            </div>
        `;
        document.getElementById('participantesContainer').appendChild(mensaje);
    } else if (!mostrar && mensajeExistente) {
        mensajeExistente.remove();
    }
}

function limpiarFiltros() {
    filtroActual = { busqueda: '', estado: '', examen: '' };
    document.getElementById('searchParticipantes').value = '';
    document.getElementById('filterEstado').value = '';
    document.getElementById('filterExamen').value = '';
    document.getElementById('clearSearch').style.display = 'none';
    aplicarFiltros();
}

function limpiarBusqueda() {
    filtroActual.busqueda = '';
    document.getElementById('searchParticipantes').value = '';
    document.getElementById('clearSearch').style.display = 'none';
    aplicarFiltros();
}

// Event listeners para los filtros
document.getElementById('searchParticipantes').addEventListener('input', function(e) {
    filtroActual.busqueda = e.target.value.toLowerCase();
    
    // Mostrar/ocultar botón de limpiar búsqueda
    const clearBtn = document.getElementById('clearSearch');
    if (e.target.value.length > 0) {
        clearBtn.style.display = 'block';
    } else {
        clearBtn.style.display = 'none';
    }
    
    aplicarFiltros();
});

document.getElementById('filterEstado').addEventListener('change', function(e) {
    filtroActual.estado = e.target.value.toLowerCase();
    aplicarFiltros();
});

document.getElementById('filterExamen').addEventListener('change', function(e) {
    filtroActual.examen = e.target.value;
    aplicarFiltros();
});

document.getElementById('clearFilters').addEventListener('click', function() {
    limpiarFiltros();
});

document.getElementById('clearSearch').addEventListener('click', function() {
    limpiarBusqueda();
});

// Aplicar filtros al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Event listener para el botón de búsqueda en INCATEC
    const btnBuscarIncatec = document.getElementById('btnBuscarIncatec');
    if (btnBuscarIncatec) {
        btnBuscarIncatec.addEventListener('click', function(e) {
            e.preventDefault();
            buscarEnIncatec();
        });
    }
    
    // También permitir búsqueda con Enter en el campo de identificación
    const inputIdentificacion = document.getElementById('identificacion');
    if (inputIdentificacion) {
        inputIdentificacion.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !document.querySelector('input[name="editar_participante"]')) {
                e.preventDefault();
                buscarEnIncatec();
            }
        });
    }
    
    // Auto-focus en el campo de búsqueda si no estamos editando
    if (!document.querySelector('input[name="editar_participante"]')) {
        inputIdentificacion?.focus();
    }
    
    // Aplicar filtros al cargar la página
    aplicarFiltros();
});

// Confirmación antes de eliminar participante
function confirmarEliminarParticipante(idParticipante, nombreParticipante, numRespuestas) {
    let mensajeAdvertencia = '';
    
    if (numRespuestas > 0) {
        mensajeAdvertencia = `
            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 15px 0;">
                <p style="margin: 0; color: #856404;"><strong>⚠️ Este participante tiene ${numRespuestas} respuestas registradas</strong></p>
                <p style="margin: 10px 0 0 0; color: #856404;">No podrás eliminarlo hasta que se eliminen sus respuestas del sistema.</p>
            </div>
        `;
    } else {
        mensajeAdvertencia = `
            <div style="background: #f8d7da; padding: 15px; border-radius: 8px; border-left: 4px solid #dc3545; margin: 15px 0;">
                <p style="margin: 0; color: #721c24;"><strong>⚠️ Advertencia:</strong></p>
                <ul style="margin: 10px 0 0 20px; color: #721c24;">
                    <li>Esta acción no se puede deshacer</li>
                    <li>Se eliminará permanentemente el participante</li>
                </ul>
            </div>
        `;
    }
    
    Swal.fire({
        title: '¿Eliminar este participante?',
        html: `
            <div style="text-align: left; margin: 20px 0;">
                <p><strong>Participante:</strong> "${nombreParticipante}"</p>
                ${mensajeAdvertencia}
            </div>
        `,
        icon: numRespuestas > 0 ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash-alt"></i> Sí, eliminar',
        cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
        reverseButtons: true,
        customClass: {
            popup: 'swal-wide'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Crear y enviar formulario
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'eliminar_participante';
            input.value = '1';
            
            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'id_participante';
            inputId.value = idParticipante;
            
            form.appendChild(input);
            form.appendChild(inputId);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Función para asignar examen
function mostrarAsignarExamen(idParticipante, nombreParticipante, nivelActual = null) {
    const esEdicion = nivelActual !== null;
    const titulo = esEdicion ? 'Cambiar Nivel de Examen' : 'Asignar Examen por Dificultad';
    
    Swal.fire({
        title: titulo,
        html: `
            <div style="text-align: left; margin: 20px 0;">
                <p><strong>Participante:</strong> ${nombreParticipante}</p>
                ${esEdicion ? `<p><strong>Nivel actual:</strong> <span class="level-current">${nivelActual.charAt(0).toUpperCase() + nivelActual.slice(1)}</span></p>` : ''}
                
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">Selecciona el nivel de dificultad:</label>
                    <select id="nivelExamen" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                        <option value="">Seleccionar nivel...</option>
                        <option value="bajo" ${nivelActual === 'bajo' ? 'selected' : ''}>🟢 Nivel Bajo - Preguntas básicas y fundamentales</option>
                        <option value="medio" ${nivelActual === 'medio' ? 'selected' : ''}>🟡 Nivel Medio - Preguntas intermedias</option>
                        <option value="alto" ${nivelActual === 'alto' ? 'selected' : ''}>🔴 Nivel Alto - Preguntas avanzadas y complejas</option>
                    </select>
                </div>
                
                <div style="background: #f0f8ff; padding: 15px; border-radius: 6px; border-left: 4px solid #2196F3; margin: 15px 0;">
                    <h4 style="margin: 0 0 10px 0; color: #1565c0;">💡 Información sobre los niveles:</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #1565c0; font-size: 13px;">
                        <li><strong>Bajo:</strong> Preguntas fundamentales y conceptos básicos</li>
                        <li><strong>Medio:</strong> Preguntas que requieren análisis y comprensión</li>
                        <li><strong>Alto:</strong> Preguntas complejas que requieren razonamiento avanzado</li>
                    </ul>
                </div>
            </div>
        `,
        icon: esEdicion ? 'info' : 'question',
        showCancelButton: true,
        confirmButtonColor: '#2196F3',
        cancelButtonColor: '#6c757d',
        confirmButtonText: `<i class="fas ${esEdicion ? 'fa-sync-alt' : 'fa-check'}"></i> ${esEdicion ? 'Cambiar Nivel' : 'Asignar Examen'}`,
        cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
        reverseButtons: true,
        customClass: {
            popup: 'swal-wide'
        },
        preConfirm: () => {
            const nivel = document.getElementById('nivelExamen').value;
            if (!nivel) {
                Swal.showValidationMessage('Por favor selecciona un nivel de dificultad');
                return false;
            }
            return nivel;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Crear y enviar formulario
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const inputAction = document.createElement('input');
            inputAction.type = 'hidden';
            inputAction.name = 'asignar_examen';
            inputAction.value = '1';
            
            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'id_participante';
            inputId.value = idParticipante;
            
            const inputNivel = document.createElement('input');
            inputNivel.type = 'hidden';
            inputNivel.name = 'nivel_dificultad';
            inputNivel.value = result.value;
            
            form.appendChild(inputAction);
            form.appendChild(inputId);
            form.appendChild(inputNivel);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Función para copiar texto al portapapeles
function copiarTexto(texto) {
    navigator.clipboard.writeText(texto).then(function() {
        Swal.fire({
            icon: 'success',
            title: '¡Copiado!',
            text: 'Texto copiado al portapapeles',
            timer: 2000,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    }).catch(function() {
        // Fallback para navegadores que no soportan clipboard API
        const textArea = document.createElement('textarea');
        textArea.value = texto;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        
        Swal.fire({
            icon: 'success',
            title: '¡Copiado!',
            text: 'Texto copiado al portapapeles',
            timer: 2000,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    });
}

// Variables para la búsqueda en INCATEC
let datosIncatec = null;
let ultimaBusqueda = '';

// Función para buscar en INCATEC
async function buscarEnIncatec() {
    const documento = document.getElementById('identificacion').value.trim();
    const btnBuscar = document.getElementById('btnBuscarIncatec');
    const resultadoDiv = document.getElementById('busquedaResultado');
    
    // Validaciones
    if (!documento) {
        Swal.fire({
            icon: 'warning',
            title: 'Campo vacío',
            text: 'Por favor ingresa un número de documento',
            confirmButtonColor: '#ffc107'
        });
        return;
    }
    
    if (!/^\d+$/.test(documento)) {
        Swal.fire({
            icon: 'warning',
            title: 'Formato inválido',
            text: 'El documento debe contener solo números',
            confirmButtonColor: '#ffc107'
        });
        return;
    }
    
    // Evitar búsquedas duplicadas
    if (documento === ultimaBusqueda) {
        return;
    }
    
    ultimaBusqueda = documento;
    
    // Cambiar estado del botón
    btnBuscar.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btnBuscar.disabled = true;
    
    // ✅ MOSTRAR SWEETALERT DE CARGA ELEGANTE CON SPINNER
Swal.fire({
    title: '🔍 Consultando INCATEC',
    html: `
        <div style="text-align: center; padding: 20px;">
            <!-- Spinner Elegante -->
            <div class="elegant-spinner-container">
                <div class="elegant-spinner">
                    <div class="spinner-ring"></div>
                    <div class="spinner-ring"></div>
                    <div class="spinner-ring"></div>
                    <div class="spinner-center">
                        <i class="fas fa-graduation-cap spinner-icon"></i>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 30px;">
                <h4 style="color: #2196F3; margin: 0 0 8px 0; font-size: 16px;">Buscando estudiante</h4>
                <p style="color: #666; margin: 0; font-size: 14px;">Documento: <strong>${documento}</strong></p>
            </div>
            
            <div class="loading-progress" style="margin-top: 20px;">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <p style="color: #999; font-size: 12px; margin: 8px 0 0 0;">Conectando con el sistema académico...</p>
            </div>
        </div>
        
        <style>
            .elegant-spinner-container {
                display: flex;
                justify-content: center;
                align-items: center;
                margin: 20px 0;
            }
            
            .elegant-spinner {
                position: relative;
                width: 80px;
                height: 80px;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            
            .spinner-ring {
                position: absolute;
                border: 2px solid transparent;
                border-radius: 50%;
                animation: spin 2s linear infinite;
            }
            
            .spinner-ring:nth-child(1) {
                width: 80px;
                height: 80px;
                border-top-color: #2196F3;
                border-right-color: #2196F3;
                animation-duration: 1.5s;
            }
            
            .spinner-ring:nth-child(2) {
                width: 60px;
                height: 60px;
                border-top-color: #4CAF50;
                border-left-color: #4CAF50;
                animation-duration: 2s;
                animation-direction: reverse;
            }
            
            .spinner-ring:nth-child(3) {
                width: 40px;
                height: 40px;
                border-top-color: #FF9800;
                border-bottom-color: #FF9800;
                animation-duration: 1.2s;
            }
            
            .spinner-center {
                position: absolute;
                z-index: 10;
                display: flex;
                justify-content: center;
                align-items: center;
                width: 30px;
                height: 30px;
                background: linear-gradient(45deg, #2196F3, #4CAF50);
                border-radius: 50%;
                box-shadow: 0 0 15px rgba(33, 150, 243, 0.3);
            }
            
            .spinner-icon {
                color: white;
                font-size: 14px;
                animation: pulse 1.5s ease-in-out infinite;
            }
            
            .progress-bar {
                width: 100%;
                height: 4px;
                background: #e0e0e0;
                border-radius: 2px;
                overflow: hidden;
                position: relative;
            }
            
            .progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #2196F3, #4CAF50, #2196F3);
                background-size: 200% 100%;
                border-radius: 2px;
                animation: progressWave 2s ease-in-out infinite;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            @keyframes pulse {
                0%, 100% { transform: scale(1); opacity: 1; }
                50% { transform: scale(1.1); opacity: 0.8; }
            }
            
            @keyframes progressWave {
                0% { background-position: 200% 0; }
                100% { background-position: -200% 0; }
            }
            
            .swal-loading-custom {
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            }
        </style>
    `,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    customClass: {
        popup: 'swal-loading-custom'
    },
    didOpen: () => {
        // Opcional: agregar efectos adicionales cuando se abra
        const spinnerContainer = document.querySelector('.elegant-spinner-container');
        if (spinnerContainer) {
            spinnerContainer.style.opacity = '0';
            spinnerContainer.style.transform = 'scale(0.8)';
            setTimeout(() => {
                spinnerContainer.style.transition = 'all 0.3s ease-out';
                spinnerContainer.style.opacity = '1';
                spinnerContainer.style.transform = 'scale(1)';
            }, 100);
        }
    }
});
    
    // Limpiar resultado anterior
    resultadoDiv.innerHTML = '';
    
    try {
        // ✅ VERIFICAR QUE EL ARCHIVO API EXISTA
        console.log('Consultando API:', `api_estudiantes.php?documento=${encodeURIComponent(documento)}`);
        
        const response = await fetch(`api_estudiantes.php?documento=${encodeURIComponent(documento)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        console.log('Respuesta de API:', data);
        
        // Cerrar SweetAlert de carga
        Swal.close();
        
        if (data.success) {
            // Estudiante encontrado
            datosIncatec = data.data;
            
            // Llenar campos automáticamente
            document.getElementById('nombre').value = data.data.nombre_completo;
            
            // Si hay correo en la respuesta, completarlo también
            if (data.data.correo) {
                document.getElementById('correo').value = data.data.correo;
            }
            
            // Mostrar información adicional en campos elegantes
            if (data.data.programa || data.data.semestre || data.data.jornada) {
                if (data.data.programa) document.getElementById('programa').value = data.data.programa;
                if (data.data.semestre) document.getElementById('semestre').value = data.data.semestre;
                if (data.data.jornada) document.getElementById('jornada').value = data.data.jornada;
                document.getElementById('camposAdicionales').style.display = 'block';
            }
            
            // Mostrar resultado exitoso with diseño mejorado
            resultadoDiv.innerHTML = `
                <div class="api-search-success">
                    <div class="success-header">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="success-title">
                            <h3>¡Estudiante Encontrado!</h3>
                            <p>Datos obtenidos desde el sistema INCATEC</p>
                        </div>
                    </div>
                    
                    <div class="student-data-card">
                        <div class="student-avatar">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        
                        <div class="student-info">
                            <div class="student-name">
                                <h4>${data.data.nombre_completo}</h4>
                                <span class="student-id">ID: ${documento}</span>
                            </div>
                            
                            <div class="student-details">
                                ${data.data.programa ? `
                                    <div class="detail-item">
                                        <i class="fas fa-book"></i>
                                        <div>
                                            <label>Programa Académico</label>
                                            <span>${data.data.programa}</span>
                                        </div>
                                    </div>
                                ` : ''}
                                
                                ${data.data.semestre ? `
                                    <div class="detail-item">
                                        <i class="fas fa-layer-group"></i>
                                        <div>
                                            <label>Semestre</label>
                                            <span>${data.data.semestre}</span>
                                        </div>
                                    </div>
                                ` : ''}
                                
                                ${data.data.jornada ? `
                                    <div class="detail-item">
                                        <i class="fas fa-clock"></i>
                                        <div>
                                            <label>Jornada</label>
                                            <span>${data.data.jornada}</span>
                                        </div>
                                    </div>
                                ` : ''}
                                
                                ${data.data.correo ? `
                                    <div class="detail-item">
                                        <i class="fas fa-envelope"></i>
                                        <div>
                                            <label>Correo Institucional</label>
                                            <span>${data.data.correo}</span>
                                        </div>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                    
                    <div class="auto-fill-notice">
                        <i class="fas fa-magic"></i>
                        <span>Los campos del formulario se han completado automáticamente</span>
                    </div>
                </div>
            `;
            
            // Mostrar notificación elegante de éxito
            Swal.fire({
                title: '✨ ¡Estudiante Encontrado!',
                html: `
                    <div style="text-align: center; padding: 10px;">
                        <div style="background: linear-gradient(135deg, #4CAF50, #45a049); color: white; padding: 15px; border-radius: 10px; margin-bottom: 15px;">
                            <i class="fas fa-graduation-cap" style="font-size: 24px; margin-bottom: 8px;"></i>
                            <h4 style="margin: 0; font-size: 16px;">${data.data.nombre_completo}</h4>
                            <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;">Datos sincronizados desde INCATEC</p>
                        </div>
                        <p style="color: #666; font-size: 13px; margin: 0;">Los campos del formulario han sido completados automáticamente</p>
                    </div>
                `,
                icon: 'success',
                timer: 4000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end',
                background: '#f8f9fa',
                customClass: {
                    popup: 'swal-toast-custom'
                }
            });
            
        } else {
            // Estudiante no encontrado

            datosIncatec = null;
            document.getElementById('camposAdicionales').style.display = 'none';
            
            resultadoDiv.innerHTML = `
                <div class="api-search-not-found">
                    <div class="not-found-header">
                        <div class="not-found-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <div class="not-found-content">
                            <h3>No encontrado en INCATEC</h3>
                            <p>${data.message || 'El estudiante no está registrado en el sistema'}</p>
                        </div>
                    </div>
                    
                    <div class="manual-continue">
                        <div class="manual-icon">
                            <i class="fas fa-edit"></i>
                        </div>
                        <div class="manual-text">
                            <h4>Continuar manualmente</h4>
                            <p>Puedes crear el participante completando los campos del formulario</p>
                        </div>
                    </div>
                </div>
            `;
            
            // Notificación informativa mejorada
            Swal.fire({
                title: '🔍 No encontrado en INCATEC',
                html: `
                    <div style="text-align: center; padding: 10px;">
                        <div style="background: linear-gradient(135deg, #ff9800, #f57c00); color: white; padding: 15px; border-radius: 10px; margin-bottom: 15px;">
                            <i class="fas fa-search" style="font-size: 24px; margin-bottom: 8px;"></i>
                            <h4 style="margin: 0; font-size: 16px;">Documento: ${documento}</h4>
                            <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;">No registrado en el sistema</p>
                        </div>
                        <p style="color: #666; font-size: 13px; margin: 0;">Puedes continuar creando el participante manualmente</p>
                    </div>
                `,
                icon: 'info',
                timer: 4000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end',
                background: '#f8f9fa',
                customClass: {
                    popup: 'swal-toast-custom'
                }
            });
        }
        
    } catch (error) {
        console.error('Error al buscar en INCATEC:', error);
        
        // Cerrar SweetAlert de carga en caso de error
        Swal.close();
        
        datosIncatec = null;
        document.getElementById('camposAdicionales').style.display = 'none';
        
        resultadoDiv.innerHTML = `
            <div class="api-search-error">
                <div class="error-header">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="error-content">
                        <h3>Error de conexión</h3>
                        <p>No se pudo conectar con el sistema INCATEC</p>
                    </div>
                </div>
                
                <div class="error-details">
                    <div class="error-actions">
                        <button onclick="buscarEnIncatec()" class="btn-retry">
                            <i class="fas fa-redo"></i> Intentar nuevamente
                        </button>
                        <span class="divider">o</span>
                        <span class="continue-manual">Continuar manualmente</span>
                    </div>
                    <div style="margin-top: 8px; font-size: 11px; color: #666;">
                        <strong>Error técnico:</strong> ${error.message}
                    </div>
                </div>
            </div>
        `;
        
        // SweetAlert de error mejorado
        Swal.fire({
            title: '⚠️ Error de conexión',
            html: `
                <div style="text-align: center; padding: 10px;">
                    <div style="background: linear-gradient(135deg, #f44336, #d32f2f); color: white; padding: 15px; border-radius: 10px; margin-bottom: 15px;">
                        <i class="fas fa-wifi" style="font-size: 24px; margin-bottom: 8px;"></i>
                        <h4 style="margin: 0; font-size: 16px;">Sin conexión a INCATEC</h4>
                        <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;">Revisa tu conexión e intenta nuevamente</p>
                    </div>
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; margin-bottom: 10px;">
                        <code style="font-size: 11px; color: #d32f2f;">${error.message}</code>
                    </div>
                    <p style="color: #666; font-size: 13px; margin: 0;">También puedes continuar creando el participante manualmente</p>
                </div>
            `,
            icon: 'error',
            timer: 8000,
            showConfirmButton: true,
            confirmButtonText: 'Entendido',
            toast: false,
            position: 'center',
            background: '#f8f9fa',
            customClass: {
                popup: 'swal-toast-custom'
            }
        });
        
    } finally {
        // Restaurar botón
        btnBuscar.innerHTML = '<i class="fas fa-search"></i>';
        btnBuscar.disabled = false;
    }
}

// Modificar el envío del formulario para incluir datos de INCATEC
document.getElementById('formParticipante').addEventListener('submit', function(e) {
    // Validaciones existentes...
    const nombre = document.querySelector('input[name="nombre"]').value.trim();
    const correo = document.querySelector('input[name="correo"]').value.trim();
    const identificacion = document.querySelector('input[name="identificacion"]').value.trim();
    
    if (!nombre || nombre.length < 3) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Nombre inválido',
            text: 'El nombre debe tener al menos 3 caracteres.',
            confirmButtonColor: '#dc3545'
        });
        return false;
    }
    
    if (!correo || !correo.includes('@')) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Correo inválido',
            text: 'Por favor ingresa un correo electrónico válido.',
            confirmButtonColor: '#dc3545'
        });
        return false;
    }
    
    if (!identificacion || identificacion.length < 3) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Identificación inválida',
            text: 'La identificación debe tener al menos 3 caracteres.',
            confirmButtonColor: '#dc3545'
        });
        return false;
    }
    
    // Si hay datos de INCATEC, agregarlos al formulario
    if (datosIncatec && !document.querySelector('input[name="editar_participante"]')) {
        // Crear campos ocultos con los datos de INCATEC
        const camposIncatec = ['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'programa', 'semestre', 'jornada', 'tipo_documento'];
        
        camposIncatec.forEach(campo => {
            if (datosIncatec.campos_individuales && datosIncatec.campos_individuales[campo]) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = campo;
                input.value = datosIncatec.campos_individuales[campo];
                this.appendChild(input);
            } else if (datosIncatec[campo]) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = campo;
                input.value = datosIncatec[campo];
                this.appendChild(input);
            }
        });
        
        // Marcar como origen API
        const inputOrigen = document.createElement('input');
        inputOrigen.type = 'hidden';
        inputOrigen.name = 'origen_datos';
        inputOrigen.value = 'api_incatec';
        this.appendChild(inputOrigen);
    }
    
    return true;
});
</script>

<style>
/* Variables CSS */
:root {
    --azul-incatec: #2196F3;
    --verde-success: #28a745;
    --rojo-danger: #dc3545;
    --naranja-warning: #ff9800;
    --gris-suave: #f8f9fa;
    --gris-oscuro: #333;
    --sombra-suave: 0 2px 4px rgba(0,0,0,0.1);
    --sombra-hover: 0 4px 8px rgba(0,0,0,0.15);
}

/* Layout general */
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.create-section {
    margin-bottom: 30px;
}

.section-divider {
    margin: 30px 0;
    border: none;
    height: 1px;
    background: #dee2e6;
}

/* Formulario mejorado */
.form-card {
    background: white;
    padding: 24px;
    border-radius: 8px;
    box-shadow: var(--sombra-suave);
    border: 1px solid #e9ecef;
    max-width: 600px;
}

.edit-notice {
    background: #e3f2fd;
    border: 1px solid #2196F3;
    border-radius: 6px;
    padding: 12px 16px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    color: #1565c0;
    font-weight: 500;
}

.btn-cancel-edit {
    background: #fff;
    color: #1565c0;
    border: 1px solid #2196F3;
    padding: 4px 8px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 12px;
    transition: all 0.2s ease;
}

.btn-cancel-edit:hover {
    background: #2196F3;
    color: white;
}

.form-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: var(--gris-oscuro);
    font-size: 14px;
}

.form-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    transition: border-color 0.2s ease;
    font-family: inherit;
}

.form-input:focus {
    outline: none;
    border-color: var(--azul-incatec);
}

.form-hint {
    color: #6c757d;
    font-size: 12px;
    margin-top: 4px;
    display: block;
}

.form-actions {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.btn-primary, .btn-secondary, .btn-danger {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    text-decoration: none;
}

.btn-primary {
    background: var(--azul-incatec);
    color: white;
}

.btn-primary:hover {
    background: #1976d2;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
}

.btn-danger {
    background: #fff5f5;
    color: var(--rojo-danger);
    border: 1px solid #f5c6cb;
}

.btn-danger:hover {
    background: #f8d7da;
    border-color: #f1aeb5;
}

.btn-large {
    padding: 12px 24px;
    font-size: 15px;
}

/* Sección de participantes */
.participantes-section {
    margin-top: 30px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.section-header h2 {
    color: var(--gris-oscuro);
    font-size: 20px;
    font-weight: 500;
    margin: 0;
}

.stats-summary {
    display: flex;
    gap: 20px;
    align-items: center;
}

.stat-item {
    display: flex;
    align-items: center;
    gap:  6px;
    color: #6c757d;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    background: white;
    color: var(--gris-oscuro);
    cursor: pointer;
    transition: border-color 0.2s ease;
    min-width: 180px;
}

.filter-select:focus {
    outline: none;
    border-color: var(--azul-incatec);
}

.btn-clear-filters {
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    background: #f8f9fa;
    color: var(--gris-oscuro);
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-clear-filters:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

/* Grid de participantes */
.participantes-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
    gap: 20px;
}

.participante-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: var(--sombra-suave);
    transition: box-shadow 0.2s ease;
    border: 1px solid #e9ecef;
    overflow: hidden;
    word-wrap: break-word;
    min-height: fit-content;
    display: flex;
    flex-direction: column;
}

.participante-card:hover {
    box-shadow: var(--sombra-hover);
}

.participante-header {
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f1f3f4;
}

.participante-info {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.participante-info h3 {
    margin: 0;
    color: var(--gris-oscuro);
    font-size: 16px;
    font-weight: 600;
    flex: 1;
}

.estado-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.estado-badge.activo {
    background: #d4edda;
    color: #155724;
}

.estado-badge.pendiente {
    background: #fff3cd;
    color: #856404;
}

.participante-content {
    margin: 16px 0;
}

.info-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    margin-bottom: 12px;
}

.info-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px;
    background: #f8f9fa;
    border-radius: 4px;
    border-left: 3px solid #e9ecef;
}

.info-item i {
    color: var(--azul-incatec);
    margin-top: 2px;
    font-size: 14px;
    flex-shrink: 0;
}

.info-item div {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}

.info-item label {
    display: block;
    font-weight: 500;
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 2px;
}

.info-item span {
    font-size: 13px;
    color: var(--gris-oscuro);
    word-break: break-all;
    overflow-wrap: break-word;
    display: block;
    line-height: 1.3;
}

.credential-text {
    background: #e8f5e8;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
    font-weight: 500;
    color: #155724 !important;
    border: 1px solid #c3e6cb;
}

.credential-hint {
    display: block;
    font-size: 10px;
    color: #6c757d;
    font-style: italic;
    margin-top: 2px;
}

.activity-info {
    background: #e8f5e8;
    padding: 8px 12px;
    border-radius: 4px;
    border-left: 3px solid var(--verde-success);
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #155724;
    margin-top: 12px;
}

/* Sección de examen asignado */
.exam-section {
    background: #f0f8ff;
    border: 1px solid #d4dbff;
    border-radius: 6px;
    padding: 12px;
    margin-top: 12px;
    overflow: hidden;
}

.exam-section h4 {
    margin: 0 0 10px 0;
    font-size: 13px;
    color: #2196F3;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.exam-assignment {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.exam-status {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}

.exam-status.no-exam {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

.exam-level-info {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.exam-level {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid;
}

.exam-level.bajo {
    background: #d4edda;
    color: #155724;
    border-color: #c3e6cb;
}

.exam-level.medio {
    background: #fff3cd;
    color: #856404;
    border-color: #ffeaa7;
}

.exam-level.alto {
    background: #f8d7da;
    color: #721c24;
    border-color: #f5c6cb;
}

.level-text {
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.exam-date {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #6c757d;
    font-style: italic;
}

.exam-actions {
    display: flex;
    gap: 6px;
    margin-top: 4px;
}

.btn-assign-exam, .btn-change-exam {
    background: #2196F3;
    color: white;
    border: none;
    padding: 6px 10px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 11px;
    transition: background-color 0.2s ease;
    display: flex;
    align-items: center;
    gap: 4px;
    font-weight: 500;
}

.btn-assign-exam:hover, .btn-change-exam:hover {
    background: #1976d2;
}

.btn-change-exam {
    background: #ff9800;
}

.btn-change-exam:hover {
    background: #f57f17;
}

/* Estilos específicos para SweetAlert de asignación */
.level-current {
    background: #e3f2fd;
    color: #1565c0;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
}

/* Sección de credenciales */
.credentials-section {
    background: #f8f9ff;
    border: 1px solid #e3e8ff;
    border-radius: 6px;
    padding: 12px;
    margin-top: 12px;
    overflow: hidden;
}

.credentials-section h4 {
    margin: 0 0 10px 0;
    font-size: 13px;
    color: #4c63d2;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.credentials-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
    margin-bottom: 8px;
}

.credential-item {
    display: flex;
    align-items: center;
    gap: 6px;
    background: white;
    padding: 8px;
    border-radius: 4px;
    border: 1px solid #d4dbff;
    min-width: 0;
    overflow: hidden;
}

.credential-item label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 500;
    min-width: 50px;
    flex-shrink: 0;
}

.credential-value {
    flex: 1;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    font-weight: 600;
    color: #4c63d2;
    background: #f0f3ff;
    padding: 4px 6px;
    border-radius: 3px;
    border: 1px solid #d4dbff;
    word-break: break-all;
    overflow-wrap: break-word;
    min-width: 0;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.credential-status {
    display: flex;
    align-items: center;
    gap: 6px;
    background: white;
    padding: 6px 8px;
    border-radius: 4px;
    border: 1px solid #d4dbff;
    grid-column: 1 / -1;
}

.credential-status label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 500;
    min-width: 50px;
}

.status-badge {
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.status-active {
    background: #d4edda;
    color: #155724;
}

.status-badge.status-pending {
    background: #fff3cd;
    color: #856404;
}

.btn-copy {
    background: #4c63d2;
    color: white;
    border: none;
    padding: 4px 6px;
    border-radius: 3px;
    cursor: pointer;
    font-size: 10px;
    transition: background-color 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    min-width: 24px;
    height: 24px;
}

.btn-copy:hover {
    background: #3749c0;
}

.credentials-note {
    color: #6c757d;
    font-size: 11px;
    display: flex;
    align-items: flex-start;
    gap: 4px;
    font-style: italic;
    line-height: 1.3;
    margin-top: 4px;
}

.migration-warning, .error-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 4px;
    padding: 8px 10px;
    color: #856404;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
    line-height: 1.3;
}

.error-warning {
    background: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
}

.participante-footer {
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid #f1f3f4;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    color: #6c757d;
    grid-column: 1 / -1;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px dashed #dee2e6;
}

.empty-state i {
    font-size: 32px;
    margin-bottom: 12px;
    color: #adb5bd;
}

.empty-state h3 {
    font-size: 16px;
    margin-bottom: 8px;
    color: var(--gris-oscuro);
}

.empty-state p {
    font-size: 14px;
    margin: 0;
}

/* Mensaje de no resultados */
.no-results-message {
    grid-column: 1 / -1;
    text-align: center;
    padding: 40px 20px;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px dashed #dee2e6;
    margin: 20px 0;
}

.no-results-content {
    max-width: 300px;
    margin: 0 auto;
}

.no-results-message i {
    font-size: 32px;
    color: #adb5bd;
    margin-bottom: 12px;
}

.no-results-message h3 {
    color: var(--gris-oscuro);
    margin-bottom: 8px;
    font-size: 16px;
}

.no-results-message p {
    color: #6c757d;
    margin-bottom: 16px;
    font-size: 14px;
}

/* SweetAlert personalizado */
.swal-wide {
    width: 500px !important;
}

/* Filtros mejorados */
.filter-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #dee2e6;
    margin-bottom: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.search-group {
    flex: 1;
}

.search-input-container {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 500px;
}

.search-icon {
    position: absolute;
    left: 12px;
    color: #6c757d;
    font-size: 14px;
    z-index: 2;
}

.search-input {
    width: 100%;
    padding: 12px 16px 12px 40px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    background: white;
    transition: all 0.2s ease;
    color: var(--gris-oscuro);
    font-family: inherit;
}

.search-input:focus {
    outline: none;
    border-color: var(--azul-incatec);
    box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.1);
}

.search-input::placeholder {
    color: #adb5bd;
    font-style: italic;
}

.btn-clear-search {
    position: absolute;
    right: 8px;
    background: #6c757d;
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 10px;
}

.btn-clear-search:hover {
    background: #5a6268;
    transform: scale(1.1);
}

.filters-group {
    display: flex;
    align-items: end;
    gap: 16px;
    flex-wrap: wrap;
}

.filter-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 180px;
}

.filter-label {
    font-size: 12px;
    font-weight: 600;
    color: #495057;
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
}

.filter-select {
    padding: 10px 12px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
    background: white;
    color: var(--gris-oscuro);
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
    appearance: none;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 8px center;
    background-repeat: no-repeat;
    background-size: 16px;
    padding-right: 40px;
}

.filter-select:focus {
    outline: none;
    border-color: var(--azul-incatec);
    box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.1);
}

.filter-select:hover {
    border-color: #adb5bd;
}

.btn-clear-filters {
    padding: 10px 16px;
    border: 2px solid #dc3545;
    border-radius: 6px;
    background: white;
    color: #dc3545;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    font-size: 14px;
    min-height: 42px;
    white-space: nowrap;
}

.btn-clear-filters:hover {
    background: #dc3545;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.2);
}

.contador-resultados {
    background: white;
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #e9ecef;
    font-size: 13px;
    color: #6c757d;
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
    align-self: flex-start;
}

/* Agregar data-nivel-examen a las tarjetas */
<?php foreach ($participantes as $p): ?>
    .participante-card[data-search*="<?= htmlspecialchars($p['identificacion']) ?>"] {
        /* El atributo data-nivel-examen se agregará desde PHP */
    }
<?php endforeach; ?>

/* Responsive para filtros */
@media (max-width: 768px) {
    .filter-section {
        padding: 16px;
        gap: 12px;
    }
    
    .search-input-container {
        max-width: none;
    }
    
    .filters-group {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    
    .filter-item {
        min-width: auto;
    }
    
}

/* Print styles */
@media print {
    .btn, .action-buttons, .filter-section {
        display: none !important;
    }
    
    .participante-card {
        break-inside: avoid;
        box-shadow: none;
        border: 1px solid #ccc;
    }
    
    .container {
        max-width: none;
        padding: 0;
    }
}
</style>
<?php require_once '../includes/footer.php'; ?>

</body>
</html>