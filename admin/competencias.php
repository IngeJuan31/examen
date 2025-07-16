<?php
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../controllers/verificar_sesion.php';
require_once '../controllers/permisos.php';
verificarPermiso('COMPETENCIAS'); // Cambia el permiso según la vista

$alerta = null;
$competencia_editar = null;

// Crear nueva competencia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_competencia'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    if ($nombre !== '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO competencias (nombre, descripcion) VALUES (?, ?)");
            $stmt->execute([$nombre, $descripcion]);
            $alerta = ['tipo' => 'success', 'mensaje' => 'Competencia creada exitosamente.'];
        } catch (PDOException $e) {
            $alerta = ['tipo' => 'error', 'mensaje' => 'Error al insertar: ' . $e->getMessage()];
        }
    } else {
        $alerta = ['tipo' => 'warning', 'mensaje' => 'El nombre es obligatorio.'];
    }
}

// Editar competencia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_competencia'])) {
    $id_competencia = $_POST['id_competencia'];
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    if ($nombre !== '') {
        try {
            // Verificar si la competencia tiene preguntas asociadas
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM preguntas WHERE id_competencia = ?");
            $stmt_check->execute([$id_competencia]);
            $tiene_preguntas = $stmt_check->fetchColumn() > 0;

            $stmt = $pdo->prepare("UPDATE competencias SET nombre = ?, descripcion = ? WHERE id_competencia = ?");
            $stmt->execute([$nombre, $descripcion, $id_competencia]);
            
            $mensaje = $tiene_preguntas ? 
                'Competencia actualizada exitosamente. Los cambios afectan a ' . $stmt_check->fetchColumn() . ' preguntas.' :
                'Competencia actualizada exitosamente.';
            
            $alerta = ['tipo' => 'success', 'mensaje' => $mensaje];
        } catch (PDOException $e) {
            $alerta = ['tipo' => 'error', 'mensaje' => 'Error al actualizar: ' . $e->getMessage()];
        }
    } else {
        $alerta = ['tipo' => 'warning', 'mensaje' => 'El nombre es obligatorio.'];
    }
}

// Eliminar competencia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_competencia'])) {
    $id_competencia = $_POST['id_competencia'];
    
    try {
        $pdo->beginTransaction();
        
        // Verificar si la competencia tiene preguntas asociadas
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM preguntas WHERE id_competencia = ?");
        $stmt_check->execute([$id_competencia]);
        $tiene_preguntas = $stmt_check->fetchColumn() > 0;
        
        if ($tiene_preguntas) {
            throw new Exception("No se puede eliminar esta competencia porque tiene preguntas asociadas. Elimina primero las preguntas o asígnalas a otra competencia.");
        }
        
        $stmt = $pdo->prepare("DELETE FROM competencias WHERE id_competencia = ?");
        $stmt->execute([$id_competencia]);
        
        $pdo->commit();
        $alerta = ['tipo' => 'success', 'mensaje' => 'Competencia eliminada exitosamente.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $alerta = ['tipo' => 'error', 'mensaje' => $e->getMessage()];
    }
}

// Obtener competencia para editar
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM competencias WHERE id_competencia = ?");
    $stmt->execute([$_GET['editar']]);
    $competencia_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Listar todas las competencias con conteo de preguntas
$competencias = $pdo->query("
    SELECT c.*, 
    (SELECT COUNT(*) FROM preguntas p WHERE p.id_competencia = c.id_competencia) AS num_preguntas
    FROM competencias c 
    ORDER BY c.nombre
")->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if ($alerta): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: '<?= $alerta['tipo'] === 'success' ? 'success' : ($alerta['tipo'] === 'warning' ? 'warning' : 'error') ?>',
        title: '<?= $alerta['tipo'] === 'success' ? '¡Éxito!' : ($alerta['tipo'] === 'warning' ? '¡Atención!' : '¡Error!') ?>',
        text: '<?= addslashes($alerta['mensaje']) ?>',
        timer: 4000,
        showConfirmButton: true,
        confirmButtonText: 'Entendido',
        confirmButtonColor: '<?= $alerta['tipo'] === 'success' ? '#28a745' : ($alerta['tipo'] === 'warning' ? '#ffc107' : '#dc3545') ?>',
        toast: false,
        position: 'center'
    });
});
</script>
<?php endif; ?>

<div class="container">
    <!-- Sección para crear/editar competencia -->
    <div class="create-section">
        <h2>
            <i class="fas <?= $competencia_editar ? 'fa-edit' : 'fa-plus-circle' ?>"></i> 
            <?= $competencia_editar ? 'Editar Competencia' : 'Crear Nueva Competencia' ?>
        </h2>
        
        <form method="POST" class="form-card" id="formCompetencia">
            <?php if ($competencia_editar): ?>
                <input type="hidden" name="editar_competencia" value="1">
                <input type="hidden" name="id_competencia" value="<?= $competencia_editar['id_competencia'] ?>">
                
                <div class="edit-notice">
                    <i class="fas fa-info-circle"></i>
                    <span>Editando competencia: <strong><?= htmlspecialchars($competencia_editar['nombre']) ?></strong></span>
                    <a href="competencias.php" class="btn-cancel-edit">
                        <i class="fas fa-times"></i> Cancelar edición
                    </a>
                </div>
            <?php else: ?>
                <input type="hidden" name="crear_competencia" value="1">
            <?php endif; ?>
            
            <div class="form-group">
                <label><i class="fas fa-tag"></i> Nombre de la competencia:</label>
                <input type="text" name="nombre" placeholder="Ej: Razonamiento Matemático, Comprensión Lectora..." 
                       required class="form-input" 
                       value="<?= $competencia_editar ? htmlspecialchars($competencia_editar['nombre']) : '' ?>">
                <small class="form-hint">Nombre identificativo de la competencia</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-align-left"></i> Descripción (opcional):</label>
                <textarea name="descripcion" placeholder="Describe brevemente qué evalúa esta competencia..." 
                         class="form-textarea" rows="3"><?= $competencia_editar ? htmlspecialchars($competencia_editar['descripcion']) : '' ?></textarea>
                <small class="form-hint">Explicación opcional sobre la competencia</small>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary btn-large">
                    <i class="fas <?= $competencia_editar ? 'fa-save' : 'fa-plus' ?>"></i> 
                    <?= $competencia_editar ? 'Actualizar Competencia' : 'Crear Competencia' ?>
                </button>
                
                <?php if ($competencia_editar): ?>
                    <a href="competencias.php" class="btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a crear nueva
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <hr class="section-divider">

    <!-- Listado de competencias -->
    <div class="competencias-section">
        <div class="section-header">
            <h2><i class="fas fa-list"></i> Competencias Registradas (<?= count($competencias) ?>)</h2>
            
            <?php if (!empty($competencias)): ?>
                <div class="stats-summary">
                    <div class="stat-item">
                        <i class="fas fa-folder"></i>
                        <span><?= count($competencias) ?> competencias</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-question-circle"></i>
                        <span><?= array_sum(array_column($competencias, 'num_preguntas')) ?> preguntas totales</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="competencias-container">
            <?php if (empty($competencias)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>¡Aún no tienes competencias!</h3>
                    <p>Crea tu primera competencia usando el formulario de arriba</p>
                </div>
            <?php else: ?>
                <?php foreach ($competencias as $c): ?>
                    <div class="competencia-card">
                        <div class="competencia-header">
                            <div class="competencia-info">
                                <h3><i class="fas fa-bookmark"></i> <?= htmlspecialchars($c['nombre']) ?></h3>
                                <div class="competencia-stats">
                                    <span class="preguntas-count <?= $c['num_preguntas'] > 0 ? 'has-questions' : 'no-questions' ?>">
                                        <i class="fas fa-question-circle"></i> 
                                        <?= $c['num_preguntas'] ?> preguntas
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="competencia-content">
                            <?php if (!empty($c['descripcion'])): ?>
                                <p class="descripcion"><?= htmlspecialchars($c['descripcion']) ?></p>
                            <?php else: ?>
                                <p class="sin-descripcion">
                                    <i class="fas fa-info-circle"></i> Sin descripción
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="competencia-footer">
                            <div class="created-info">
                                <small>ID: <?= $c['id_competencia'] ?></small>
                            </div>
                            
                            <div class="action-buttons">
                                <a href="competencias.php?editar=<?= $c['id_competencia'] ?>" 
                                   class="btn-secondary btn-edit">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                
                                <button class="btn-danger btn-delete" 
                                        onclick="confirmarEliminarCompetencia(<?= $c['id_competencia'] ?>, '<?= addslashes(htmlspecialchars($c['nombre'])) ?>', <?= $c['num_preguntas'] ?>)">
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
// Confirmación antes de eliminar competencia
function confirmarEliminarCompetencia(idCompetencia, nombreCompetencia, numPreguntas) {
    let mensajeAdvertencia = '';
    
    if (numPreguntas > 0) {
        mensajeAdvertencia = `
            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 15px 0;">
                <p style="margin: 0; color: #856404;"><strong>⚠️ Esta competencia tiene ${numPreguntas} preguntas asociadas</strong></p>
                <p style="margin: 10px 0 0 0; color: #856404;">No podrás eliminarla hasta que reasignes o elimines todas las preguntas.</p>
            </div>
        `;
    } else {
        mensajeAdvertencia = `
            <div style="background: #f8d7da; padding: 15px; border-radius: 8px; border-left: 4px solid #dc3545; margin: 15px 0;">
                <p style="margin: 0; color: #721c24;"><strong>⚠️ Advertencia:</strong></p>
                <ul style="margin: 10px 0 0 20px; color: #721c24;">
                    <li>Esta acción no se puede deshacer</li>
                    <li>Se eliminará permanentemente la competencia</li>
                </ul>
            </div>
        `;
    }
    
    Swal.fire({
        title: '¿Eliminar esta competencia?',
        html: `
            <div style="text-align: left; margin: 20px 0;">
                <p><strong>Competencia:</strong> "${nombreCompetencia}"</p>
                ${mensajeAdvertencia}
            </div>
        `,
        icon: numPreguntas > 0 ? 'warning' : 'question',
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
            input.name = 'eliminar_competencia';
            input.value = '1';
            
            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'id_competencia';
            inputId.value = idCompetencia;
            
            form.appendChild(input);
            form.appendChild(inputId);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Validación del formulario
document.getElementById('formCompetencia').addEventListener('submit', function(e) {
    const nombre = document.querySelector('input[name="nombre"]').value.trim();
    
    if (!nombre) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Campo requerido',
            text: 'Por favor ingresa el nombre de la competencia.',
            confirmButtonColor: '#dc3545'
        });
        return false;
    }
    
    if (nombre.length < 3) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Nombre muy corto',
            text: 'El nombre debe tener al menos 3 caracteres.',
            confirmButtonColor: '#dc3545'
        });
        return false;
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

.form-input, .form-textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    transition: border-color 0.2s ease;
    font-family: inherit;
}

.form-input:focus, .form-textarea:focus {
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

/* Sección de competencias */
.competencias-section {
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
    gap: 6px;
    color: #6c757d;
    font-size: 14px;
}

.stat-item i {
    color: var(--azul-incatec);
}

/* Grid de competencias */
.competencias-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}

.competencia-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: var(--sombra-suave);
    transition: box-shadow 0.2s ease;
    border: 1px solid #e9ecef;
}

.competencia-card:hover {
    box-shadow: var(--sombra-hover);
}

.competencia-header {
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f1f3f4;
}

.competencia-info h3 {
    margin: 0 0 8px 0;
    color: var(--gris-oscuro);
    font-size: 16px;
    font-weight: 600;
}

.competencia-stats {
    display: flex;
    gap: 12px;
}

.preguntas-count {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.preguntas-count.has-questions {
    background: #d4edda;
    color: #155724;
}

.preguntas-count.no-questions {
    background: #f8d7da;
    color: #721c24;
}

.competencia-content {
    margin: 12px 0;
}

.descripcion {
    margin: 0;
    line-height: 1.5;
    color: #495057;
    font-size: 14px;
}

.sin-descripcion {
    margin: 0;
    color: #adb5bd;
    font-style: italic;
    font-size: 13px;
}

.competencia-footer {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #f1f3f4;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.created-info {
    color: #6c757d;
    font-size: 12px;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.btn-edit, .btn-delete {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 500;
    border: 1px solid;
}

.btn-edit {
    background: #f8f9fa;
    color: #495057;
    border-color: #dee2e6;
}

.btn-edit:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

/* Estado vacío */
.empty-state {
    text-align: center;
    padding: 40px 20px;
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

/* SweetAlert personalizado */
.swal-wide {
    width: 500px !important;
}

/* Responsive */
@media (max-width: 768px) {
    .container {
        padding: 16px;
    }
    
    .section-header {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    
    .stats-summary {
        flex-direction: column;
        gap: 8px;
        align-items: stretch;
    }
    
    .competencias-container {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .competencia-footer {
        flex-direction: column;
        gap: 12px;
        align-items: stretch;
    }
    
    .action-buttons {
        justify-content: center;
    }
    
    .form-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .btn-primary, .btn-secondary {
        text-align: center;
        justify-content: center;
    }
    
    .edit-notice {
        flex-direction: column;
        text-align: center;
        gap: 8px;
    }
    
    .swal-wide {
        width: 95% !important;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>
