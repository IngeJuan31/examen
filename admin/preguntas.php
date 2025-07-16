<?php
// Limpiar cualquier salida previa
ob_start();

require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../controllers/verificar_sesion.php';
require_once '../controllers/permisos.php';
verificarPermiso('PREGUNTAS'); // Cambia el permiso según la vista
$alerta = null;
$pregunta_editar = null;

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Limpiar buffer de salida antes de enviar JSON
    ob_clean();
    
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'crear':
                $stmt = $pdo->prepare("INSERT INTO preguntas (id_competencia, enunciado, tipo, nivel_dificultad, imagen_url) VALUES (?, ?, ?, ?, ?)");
                
                $imagen_url = null;
                if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] == 0) {
                    $imagen_url = subirImagen($_FILES['imagen'], 'preguntas');
                }
                
                $stmt->execute([
                    $_POST['id_competencia'],
                    $_POST['enunciado'],
                    $_POST['tipo'],
                    $_POST['nivel_dificultad'],
                    $imagen_url
                ]);
                
                $id_pregunta = $pdo->lastInsertId();
                
                // ✅ CORRECCIÓN: Insertar opciones con UNA sola respuesta correcta
                if (isset($_POST['opciones']) && is_array($_POST['opciones'])) {
                    $respuesta_correcta_index = isset($_POST['respuesta_correcta_index']) ? $_POST['respuesta_correcta_index'] : null;
                    
                    foreach ($_POST['opciones'] as $index => $opcion) {
                        if (!empty(trim($opcion['texto']))) {
                            $opcion_imagen = null;
                            if (isset($_FILES['opcion_imagen_' . $index]) && $_FILES['opcion_imagen_' . $index]['error'] == 0) {
                                $opcion_imagen = subirImagen($_FILES['opcion_imagen_' . $index], 'opciones');
                            }
                            
                            // ✅ CORRECCIÓN: Solo marcar como correcta si es el índice seleccionado
                            $es_correcta = ($respuesta_correcta_index == $index) ? 1 : 0;
                            
                            $stmt_opcion = $pdo->prepare("INSERT INTO opciones (id_pregunta, texto, es_correcta, imagen_url) VALUES (?, ?, ?, ?)");
                            $stmt_opcion->bindValue(1, $id_pregunta, PDO::PARAM_INT);
                            $stmt_opcion->bindValue(2, trim($opcion['texto']), PDO::PARAM_STR);
                            $stmt_opcion->bindValue(3, $es_correcta, PDO::PARAM_BOOL);
                            $stmt_opcion->bindValue(4, $opcion_imagen, PDO::PARAM_STR);
                            $stmt_opcion->execute();
                        }
                    }
                }
                
                echo json_encode(['success' => true, 'message' => 'Pregunta creada exitosamente']);
                break;
                
            case 'actualizar':
                $stmt = $pdo->prepare("UPDATE preguntas SET id_competencia = ?, enunciado = ?, tipo = ?, nivel_dificultad = ?, imagen_url = COALESCE(?, imagen_url) WHERE id_pregunta = ?");
                
                $imagen_url = null;
                if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] == 0) {
                    $imagen_url = subirImagen($_FILES['imagen'], 'preguntas');
                }
                
                $stmt->execute([
                    $_POST['id_competencia'],
                    $_POST['enunciado'],
                    $_POST['tipo'],
                    $_POST['nivel_dificultad'],
                    $imagen_url,
                    $_POST['id_pregunta']
                ]);
                
                // ✅ CORRECCIÓN: Actualizar opciones with UNA sola respuesta correcta
                if (isset($_POST['opciones']) && is_array($_POST['opciones'])) {
                    $respuesta_correcta_index = isset($_POST['respuesta_correcta_index']) ? $_POST['respuesta_correcta_index'] : null;
                    
                    // Eliminar opciones que ya no están
                    $ids_opciones = array_filter(array_column($_POST['opciones'], 'id_opcion'));
                    if (!empty($ids_opciones)) {
                        $placeholders = str_repeat('?,', count($ids_opciones) - 1) . '?';
                        $stmt_delete = $pdo->prepare("DELETE FROM opciones WHERE id_pregunta = ? AND id_opcion NOT IN ($placeholders)");
                        $stmt_delete->execute(array_merge([$_POST['id_pregunta']], $ids_opciones));
                    } else {
                        $stmt_delete = $pdo->prepare("DELETE FROM opciones WHERE id_pregunta = ?");
                        $stmt_delete->execute([$_POST['id_pregunta']]);
                    }
                    
                    foreach ($_POST['opciones'] as $index => $opcion) {
                        if (!empty(trim($opcion['texto']))) {
                            $opcion_imagen = null;
                            if (isset($_FILES['opcion_imagen_' . $index]) && $_FILES['opcion_imagen_' . $index]['error'] == 0) {
                                $opcion_imagen = subirImagen($_FILES['opcion_imagen_' . $index], 'opciones');
                            }
                            
                            // ✅ CORRECCIÓN: Solo marcar como correcta si es el índice seleccionado
                            $es_correcta = ($respuesta_correcta_index == $index) ? 1 : 0;
                            
                            if (isset($opcion['id_opcion']) && !empty($opcion['id_opcion'])) {
                                // Actualizar opción existente
                                $stmt_opcion = $pdo->prepare("UPDATE opciones SET texto = ?, es_correcta = ?, imagen_url = COALESCE(?, imagen_url) WHERE id_opcion = ?");
                                $stmt_opcion->bindValue(1, trim($opcion['texto']), PDO::PARAM_STR);
                                $stmt_opcion->bindValue(2, $es_correcta, PDO::PARAM_BOOL);
                                $stmt_opcion->bindValue(3, $opcion_imagen, PDO::PARAM_STR);
                                $stmt_opcion->bindValue(4, $opcion['id_opcion'], PDO::PARAM_INT);
                                $stmt_opcion->execute();
                            } else {
                                // Insertar nueva opción
                                $stmt_opcion = $pdo->prepare("INSERT INTO opciones (id_pregunta, texto, es_correcta, imagen_url) VALUES (?, ?, ?, ?)");
                                $stmt_opcion->bindValue(1, $_POST['id_pregunta'], PDO::PARAM_INT);
                                $stmt_opcion->bindValue(2, trim($opcion['texto']), PDO::PARAM_STR);
                                $stmt_opcion->bindValue(3, $es_correcta, PDO::PARAM_BOOL);
                                $stmt_opcion->bindValue(4, $opcion_imagen, PDO::PARAM_STR);
                                $stmt_opcion->execute();
                            }
                        }
                    }
                }
                
                echo json_encode(['success' => true, 'message' => 'Pregunta actualizada exitosamente']);
                break;
                
            case 'eliminar':
                $stmt = $pdo->prepare("DELETE FROM preguntas WHERE id_pregunta = ?");
                $stmt->execute([$_POST['id_pregunta']]);
                echo json_encode(['success' => true, 'message' => 'Pregunta eliminada exitosamente']);
                break;
                
            case 'obtener':
                $stmt = $pdo->prepare("
                    SELECT p.*, c.nombre as competencia_nombre 
                    FROM preguntas p 
                    LEFT JOIN competencias c ON p.id_competencia = c.id_competencia 
                    WHERE p.id_pregunta = ?
                ");
                $stmt->execute([$_POST['id_pregunta']]);
                $pregunta = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$pregunta) {
                    echo json_encode(['success' => false, 'message' => 'Pregunta no encontrada']);
                    break;
                }
                
                $stmt_opciones = $pdo->prepare("SELECT * FROM opciones WHERE id_pregunta = ? ORDER BY id_opcion");
                $stmt_opciones->execute([$_POST['id_pregunta']]);
                $opciones = $stmt_opciones->fetchAll(PDO::FETCH_ASSOC);
                
                // CORRECCIÓN: Convertir valores booleanos de forma consistente
                foreach ($opciones as &$opcion) {
                    // Asegurar que es_correcta sea un boolean válido
                    $opcion['es_correcta'] = ($opcion['es_correcta'] === 't' || 
                                            $opcion['es_correcta'] === true || 
                                            $opcion['es_correcta'] === 1 || 
                                            $opcion['es_correcta'] === '1') ? true : false;
                }
                
                $pregunta['opciones'] = $opciones;
                
                echo json_encode(['success' => true, 'data' => $pregunta]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Finalizar buffer de salida para el resto de la página
ob_end_flush();

// Función para subir imágenes
function subirImagen($archivo, $carpeta) {
    $directorio = "../uploads/$carpeta/";
    if (!file_exists($directorio)) {
        mkdir($directorio, 0777, true);
    }
    
    $extension = pathinfo($archivo['name'], PATHINFO_EXTENSION);
    $nombre_archivo = uniqid() . '.' . $extension;
    $ruta_destino = $directorio . $nombre_archivo;
    
    if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
        return "uploads/$carpeta/" . $nombre_archivo;
    }
    return null;
}

// Obtener competencias para el select
$stmt = $pdo->prepare("SELECT id_competencia, nombre FROM competencias ORDER BY nombre");
$stmt->execute();
$competencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener preguntas con filtros
$where_conditions = [];
$params = [];

if (isset($_GET['competencia']) && !empty($_GET['competencia'])) {
    $where_conditions[] = "p.id_competencia = ?";
    $params[] = $_GET['competencia'];
}

if (isset($_GET['nivel']) && !empty($_GET['nivel'])) {
    $where_conditions[] = "p.nivel_dificultad = ?";
    $params[] = $_GET['nivel'];
}

if (isset($_GET['tipo']) && !empty($_GET['tipo'])) {
    $where_conditions[] = "p.tipo = ?";
    $params[] = $_GET['tipo'];
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$stmt = $pdo->prepare("
    SELECT p.*, c.nombre as competencia_nombre,
           (SELECT COUNT(*) FROM opciones WHERE id_pregunta = p.id_pregunta) as total_opciones,
           (SELECT COUNT(*) FROM opciones WHERE id_pregunta = p.id_pregunta AND es_correcta = true) as opciones_correctas
    FROM preguntas p 
    LEFT JOIN competencias c ON p.id_competencia = c.id_competencia 
    $where_clause
    ORDER BY p.id_pregunta DESC
");
$stmt->execute($params);
$preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
    <!-- Sección para crear nueva pregunta -->
    <div class="create-section">
        <h2>
            <i class="fas fa-plus-circle"></i> 
            Crear Nueva Pregunta
        </h2>
        
        <div class="form-card">
            <button type="button" class="btn-primary btn-large" onclick="abrirModalPregunta()">
                <i class="fas fa-plus me-1"></i>Nueva Pregunta
            </button>
            <small class="form-hint" style="display: block; margin-top: 8px;">
                Crea preguntas con opciones múltiples para tus exámenes
            </small>
        </div>
    </div>

    <!-- Filtros Simples -->
    <?php if (!empty($competencias)): ?>
    <div class="filters-simple">
        <div class="filters-header">
            <h3><i class="fas fa-search"></i> Buscar Preguntas</h3>
        </div>
        
        <form method="GET" class="filters-form">
            <div class="filters-row">
                <!-- Filtro por Competencia -->
                <div class="filter-group">
                    <label><i class="fas fa-graduation-cap"></i> Competencia</label>
                    <select name="competencia" class="filter-select">
                        <option value="">📚 Todas</option>
                        <?php foreach ($competencias as $competencia): ?>
                            <option value="<?= $competencia['id_competencia'] ?>" 
                                    <?= (isset($_GET['competencia']) && $_GET['competencia'] == $competencia['id_competencia']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($competencia['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Filtro por Nivel -->
                <div class="filter-group">
                    <label><i class="fas fa-signal"></i> Dificultad</label>
                    <select name="nivel" class="filter-select">
                        <option value="">⚡ Todos</option>
                        <option value="bajo" <?= (isset($_GET['nivel']) && $_GET['nivel'] == 'bajo') ? 'selected' : '' ?>>🟢 Bajo</option>
                        <option value="medio" <?= (isset($_GET['nivel']) && $_GET['nivel'] == 'medio') ? 'selected' : '' ?>>🟡 Medio</option>
                        <option value="alto" <?= (isset($_GET['nivel']) && $_GET['nivel'] == 'alto') ? 'selected' : '' ?>>🔴 Difícil</option>
                    </select>
                </div>
                
                <!-- Botones -->
                <div class="filter-actions">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                    <a href="preguntas.php" class="btn-clear">
                        <i class="fas fa-eraser"></i> Limpiar
                    </a>
                </div>
            </div>
            
            <!-- Resultados Info -->
            <?php if (isset($_GET['competencia']) || isset($_GET['nivel'])): ?>
            <div class="results-info">
                <span class="results-count">
                    <i class="fas fa-info-circle"></i>
                    Mostrando <?= count($preguntas) ?> pregunta<?= count($preguntas) != 1 ? 's' : '' ?>
                    
                    <?php if (isset($_GET['competencia']) && !empty($_GET['competencia'])): ?>
                        <?php 
                        $comp_filtrada = '';
                        foreach ($competencias as $comp) {
                            if ($comp['id_competencia'] == $_GET['competencia']) {
                                $comp_filtrada = $comp['nombre'];
                                break;
                            }
                        }
                        ?>
                        <span class="filter-tag">📚 <?= htmlspecialchars($comp_filtrada) ?></span>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['nivel']) && !empty($_GET['nivel'])): ?>
                        <span class="filter-tag">
                            <?php if ($_GET['nivel'] == 'bajo'): ?>🟢 Fácil
                            <?php elseif ($_GET['nivel'] == 'medio'): ?>🟡 Medio
                            <?php else: ?>🔴 Difícil
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <hr class="section-divider">

    <!-- Listado de preguntas -->
    <div class="competencias-section">
        <div class="section-header">
            <h2><i class="fas fa-question-circle"></i> Preguntas Registradas (<?= count($preguntas) ?>)</h2>
            
            <?php if (!empty($preguntas)): ?>
                <div class="stats-summary">
                    <div class="stat-item">
                        <i class="fas fa-question-circle"></i>
                        <span><?= count($preguntas) ?> preguntas</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-list"></i>
                        <span><?= array_sum(array_column($preguntas, 'total_opciones')) ?> opciones totales</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="competencias-container">
            <?php if (empty($preguntas)): ?>
                <div class="empty-state">
                    <i class="fas fa-question-circle"></i>
                    <h3>¡Aún no tienes preguntas!</h3>
                    <p>Crea tu primera pregunta usando el botón de arriba</p>
                </div>
            <?php else: ?>
                <?php foreach ($preguntas as $p): ?>
                    <div class="competencia-card">
                        <div class="competencia-header">
                            <div class="competencia-info">
                                <h3>
                                    <i class="fas fa-question"></i> 
                                    <?= htmlspecialchars(substr($p['enunciado'], 0, 60)) ?><?= strlen($p['enunciado']) > 60 ? '...' : '' ?>
                                </h3>
                                <div class="competencia-stats">
                                    <span class="preguntas-count has-questions">
                                        <i class="fas fa-tag"></i> 
                                        <?= htmlspecialchars($p['competencia_nombre']) ?>
                                    </span>
                                    <span class="preguntas-count <?= $p['nivel_dificultad'] == 'bajo' ? 'has-questions' : ($p['nivel_dificultad'] == 'medio' ? 'preguntas-count' : 'no-questions') ?>" 
                                          style="background: <?= $p['nivel_dificultad'] == 'bajo' ? '#d4edda' : ($p['nivel_dificultad'] == 'medio' ? '#fff3cd' : '#f8d7da') ?>; 
                                                 color: <?= $p['nivel_dificultad'] == 'bajo' ? '#155724' : ($p['nivel_dificultad'] == 'medio' ? '#856404' : '#721c24') ?>;">
                                        <i class="fas fa-signal"></i> 
                                        <?= ucfirst($p['nivel_dificultad']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="competencia-content">
                            <p class="descripcion"><?= htmlspecialchars($p['enunciado']) ?></p>
                            
                            <?php if ($p['imagen_url']): ?>
                                <div style="margin-top: 8px;">
                                    <img src="../<?= htmlspecialchars($p['imagen_url']) ?>" 
                                         style="max-width: 100%; height: auto; max-height: 150px; border-radius: 4px; object-fit: cover;" 
                                         alt="Imagen de pregunta">
                                </div>
                            <?php endif; ?>
                            
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f1f3f4;">
                                <small style="color: #6c757d;">
                                    <i class="fas fa-list"></i> <?= $p['total_opciones'] ?> opciones 
                                    • <i class="fas fa-check-circle"></i> <?= $p['opciones_correctas'] ?> correctas
                                </small>
                            </div>
                        </div>
                        
                        <div class="competencia-footer">
                            <div class="created-info">
                                <small>ID: <?= $p['id_pregunta'] ?> • <?= ucfirst($p['tipo']) ?></small>
                            </div>
                            
                            <div class="action-buttons">
                                <button class="btn-secondary btn-edit" onclick="editarPregunta(<?= $p['id_pregunta'] ?>)">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                
                                <button class="btn-danger btn-delete" onclick="confirmarEliminarPregunta(<?= $p['id_pregunta'] ?>, '<?= addslashes(htmlspecialchars(substr($p['enunciado'], 0, 50))) ?>')">
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

<!-- Modal para crear/editar pregunta -->
<div class="modal fade" id="modalPregunta" tabindex="-1" style="display: none;">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitulo">Nueva Pregunta</h5>
                <button type="button" class="btn-close" onclick="cerrarModal()">&times;</button>
            </div>
            <form id="formPregunta" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" id="id_pregunta" name="id_pregunta">
                    <input type="hidden" id="action" name="action" value="crear">
                    
                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="id_competencia">Competencia *</label>
                            <select class="form-input" id="id_competencia" name="id_competencia" required>
                                <option value="">Seleccionar competencia</option>
                                <?php foreach ($competencias as $competencia): ?>
                                    <option value="<?= $competencia['id_competencia'] ?>">
                                        <?= htmlspecialchars($competencia['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="tipo">Tipo *</label>
                            <select class="form-input" id="tipo" name="tipo" required>
                                <option value="opcion_multiple">Opción múltiple</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="nivel_dificultad">Nivel *</label>
                            <select class="form-input" id="nivel_dificultad" name="nivel_dificultad" required>
                                <option value="bajo">Bajo</option>
                                <option value="medio">Medio</option>
                                <option value="alto">Alto</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="enunciado">Enunciado de la pregunta *</label>
                        <textarea class="form-textarea" id="enunciado" name="enunciado" rows="3" required 
                                  placeholder="Escribe aquí la pregunta que quieres hacer..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="imagen">Imagen de la pregunta (opcional)</label>
                        <input type="file" class="form-input" id="imagen" name="imagen" accept="image/*">
                        <small class="form-hint">Formatos: JPG, PNG, GIF. Máximo 2MB</small>
                        <div id="preview_imagen" style="margin-top: 8px;"></div>
                    </div>
                    
                    <hr style="margin: 20px 0;">
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h6 style="margin: 0;"><i class="fas fa-list"></i> Opciones de respuesta</h6>
                        <button type="button" class="btn-secondary" onclick="agregarOpcion()">
                            <i class="fas fa-plus"></i> Agregar opción
                        </button>
                    </div>
                    
                    <div id="contenedor_opciones">
                        <!-- Las opciones se cargarán aquí dinámicamente -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Overlay del modal -->
<div class="modal-backdrop" id="modalBackdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1040;"></div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let contadorOpciones = 0;

// Función helper para escapar HTML
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Función para abrir modal de pregunta
function abrirModalPregunta() {
    limpiarModal();
    document.getElementById('modalPregunta').style.display = 'block';
    document.getElementById('modalBackdrop').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Función para cerrar modal
function cerrarModal() {
    document.getElementById('modalPregunta').style.display = 'none';
    document.getElementById('modalBackdrop').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Función para limpiar el modal
function limpiarModal() {
    document.getElementById('formPregunta').reset();
    document.getElementById('modalTitulo').textContent = 'Nueva Pregunta';
    document.getElementById('action').value = 'crear';
    document.getElementById('id_pregunta').value = '';
    document.getElementById('preview_imagen').innerHTML = '';
    document.getElementById('contenedor_opciones').innerHTML = '';
    contadorOpciones = 0;
    
    // Agregar dos opciones por defecto
    agregarOpcion();
    agregarOpcion();
}

// Función para agregar una nueva opción - MODIFICADA PARA RADIO BUTTONS
function agregarOpcion(datos = null) {
    const contenedor = document.getElementById('contenedor_opciones');
    const index = contadorOpciones++;
    
    const div = document.createElement('div');
    div.className = 'form-card';
    div.id = `opcion_${index}`;
    div.style.marginBottom = '16px';
    
    // CORRECCIÓN: Mejor manejo de valores booleanos para radio
    let esCorrecta = false;
    if (datos && datos.es_correcta !== undefined) {
        esCorrecta = datos.es_correcta === true || 
                    datos.es_correcta === 't' || 
                    datos.es_correcta === '1' || 
                    datos.es_correcta === 1;
    }
    
    div.innerHTML = `
        <div style="display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: start;">
            <div>
                <label class="form-group" style="margin-bottom: 8px;">
                    <span style="display: block; margin-bottom: 6px; font-weight: 500; color: var(--gris-oscuro); font-size: 14px;">
                        <i class="fas fa-list-ul"></i> Texto de la opción *
                        ${datos ? `<small style="color: #28a745;">(ID: ${datos.id_opcion || 'Nueva'})</small>` : '<small style="color: #6c757d;">(Nueva)</small>'}
                    </span>
                    <textarea class="form-textarea" name="opciones[${index}][texto]" rows="2" required 
                              placeholder="Escribe la opción de respuesta...">${datos ? escapeHtml(datos.texto || '') : ''}</textarea>
                    <input type="hidden" name="opciones[${index}][id_opcion]" value="${datos ? (datos.id_opcion || '') : ''}">
                </label>
                
                <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; margin-top: 12px;">
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-size: 13px; color: #6c757d;">
                            <i class="fas fa-image"></i> Imagen (opcional)
                        </label>
                        <input type="file" class="form-input" name="opcion_imagen_${index}" accept="image/*" 
                               style="font-size: 13px; padding: 6px 8px;">
                        ${datos && datos.imagen_url ? `
                            <small style="color: #28a745; font-size: 11px; display: block; margin-top: 4px;">
                                <i class="fas fa-check-circle"></i> Imagen cargada
                            </small>
                        ` : ''}
                    </div>
                    <div style="text-align: center;">
                        <label class="radio-correcta" style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px 16px; 
                                      border-radius: 6px; border: 2px solid #28a745; background: ${esCorrecta ? '#28a745' : '#fff'}; 
                                      color: ${esCorrecta ? '#fff' : '#28a745'}; font-weight: 500; font-size: 14px; transition: all 0.3s ease;
                                      min-width: 120px; justify-content: center;">
                            <input type="radio" name="respuesta_correcta" value="${index}" ${esCorrecta ? 'checked' : ''} 
                                   style="display: none;" onchange="actualizarRespuestaCorrecta(this)">
                            <i class="fas fa-check-circle"></i>
                            <span>Correcta</span>
                        </label>
                    </div>
                </div>
                
                ${datos && datos.imagen_url ? `
                    <div style="margin-top: 12px; padding: 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef;">
                        <p style="margin: 0 0 8px 0; font-size: 12px; color: #6c757d; font-weight: 500;">
                            <i class="fas fa-image"></i> Imagen actual:
                        </p>
                        <img src="../${datos.imagen_url}" 
                             style="max-width: 120px; max-height: 120px; object-fit: cover; border-radius: 4px; border: 1px solid #dee2e6;" 
                             alt="Imagen actual de la opción">
                    </div>
                ` : ''}
            </div>
            
            <div style="text-align: center;">
                <button type="button" class="btn-danger" onclick="eliminarOpcion(${index})" 
                        style="padding: 8px 12px; font-size: 12px; border-radius: 6px;" title="Eliminar opción">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </div>
    `;
    
    contenedor.appendChild(div);
}

// Nueva función para actualizar el estilo de radio buttons
function actualizarRespuestaCorrecta(radio) {
    // Limpiar todos los estilos de radio buttons
    const radios = document.querySelectorAll('input[name="respuesta_correcta"]');
    radios.forEach(r => {
        const label = r.parentElement;
        label.style.background = '#fff';
        label.style.color = '#28a745';
        label.style.borderColor = '#28a745';
        label.style.transform = 'none';
        label.style.boxShadow = 'none';
    });
    
    // Aplicar estilo al seleccionado
    if (radio.checked) {
        const label = radio.parentElement;
        label.style.background = '#28a745';
        label.style.color = '#fff';
        label.style.borderColor = '#28a745';
        label.style.transform = 'translateY(-2px)';
        label.style.boxShadow = '0 4px 12px rgba(40, 167, 69, 0.3)';
    }
}

// Función para eliminar una opción - MEJORADA
function eliminarOpcion(index) {
    const elemento = document.getElementById(`opcion_${index}`);
    if (elemento) {
        // Verificar si esta opción está marcada como correcta
        const radioCorrecta = elemento.querySelector('input[name="respuesta_correcta"]');
        const estaSeleccionada = radioCorrecta && radioCorrecta.checked;
        
        elemento.remove();
        
        // Si la opción eliminada era la correcta, seleccionar la primera disponible
        if (estaSeleccionada) {
            setTimeout(() => {
                const primeraOpcion = document.querySelector('input[name="respuesta_correcta"]');
                if (primeraOpcion) {
                    primeraOpcion.checked = true;
                    actualizarRespuestaCorrecta(primeraOpcion);
                }
            }, 10);
        }
    }
}

// Función para editar pregunta - CORREGIDA PARA CARGAR RESPUESTA CORRECTA
function editarPregunta(id) {
    console.log('=== INICIO EDICIÓN ===');
    console.log('ID a editar:', id);
    
    fetch('preguntas.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=obtener&id_pregunta=${id}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('=== DATOS RECIBIDOS ===');
        console.log('Data completa:', data);
        
        if (data.success && data.data) {
            // ✅ PRIMERO: Configurar el modal para edición (SIN limpiar)
            document.getElementById('modalTitulo').textContent = 'Editar Pregunta';
            document.getElementById('action').value = 'actualizar';
            document.getElementById('id_pregunta').value = data.data.id_pregunta;
            
            // ✅ SEGUNDO: Cargar datos básicos de la pregunta
            document.getElementById('id_competencia').value = data.data.id_competencia || '';
            document.getElementById('enunciado').value = data.data.enunciado || '';
            document.getElementById('tipo').value = data.data.tipo || 'opcion_multiple';
            document.getElementById('nivel_dificultad').value = data.data.nivel_dificultad || 'medio';
            
            // ✅ TERCERO: Mostrar imagen de la pregunta si existe
            const previewImg = document.getElementById('preview_imagen');
            if (data.data.imagen_url) {
                previewImg.innerHTML = `
                    <img src="../${data.data.imagen_url}" style="max-width: 150px; max-height: 150px; object-fit: cover; border-radius: 4px; border: 1px solid #dee2e6;" alt="Imagen actual">
                    <p style="margin: 4px 0 0 0; font-size: 12px; color: #6c757d;">
                        <i class="fas fa-image"></i> Imagen actual de la pregunta
                    </p>
                `;
            } else {
                previewImg.innerHTML = '';
            }
            
            // ✅ CUARTO: Limpiar solo el contenedor de opciones
            const contenedorOpciones = document.getElementById('contenedor_opciones');
            contenedorOpciones.innerHTML = '';
            contadorOpciones = 0;
            
            console.log('=== CARGANDO OPCIONES EXISTENTES ===');
            console.log('Opciones recibidas:', data.data.opciones);
            
            // ✅ QUINTO: Cargar opciones existentes y marcar la correcta
            if (data.data.opciones && Array.isArray(data.data.opciones) && data.data.opciones.length > 0) {
                let indiceCorrecta = -1;
                
                data.data.opciones.forEach((opcion, index) => {
                    console.log(`✅ Cargando opción ${index + 1}:`, {
                        id: opcion.id_opcion,
                        texto: opcion.texto,
                        correcta: opcion.es_correcta,
                        imagen: opcion.imagen_url
                    });
                    
                    // ✅ CORRECCIÓN: Detectar cuál es la correcta ANTES de agregar
                    const esCorrecta = opcion.es_correcta === true || 
                                     opcion.es_correcta === 't' || 
                                     opcion.es_correcta === '1' || 
                                     opcion.es_correcta === 1;
                    
                    if (esCorrecta) {
                        indiceCorrecta = contadorOpciones; // El índice que va a tener
                        console.log(`🎯 Opción ${index + 1} es la correcta, será índice ${indiceCorrecta}`);
                    }
                    
                    agregarOpcion(opcion);
                });
                
                // ✅ SEXTO: Marcar la respuesta correcta después de cargar todas las opciones
                setTimeout(() => {
                    if (indiceCorrecta >= 0) {
                        const radioCorrecta = document.querySelector(`input[name="respuesta_correcta"][value="${indiceCorrecta}"]`);
                        if (radioCorrecta) {
                            radioCorrecta.checked = true;
                            actualizarRespuestaCorrecta(radioCorrecta);
                            console.log(`✅ Marcada opción ${indiceCorrecta} como correcta`);
                        } else {
                            console.error(`❌ No se encontró radio con valor ${indiceCorrecta}`);
                        }
                    } else {
                        console.warn('⚠️ No se encontró opción correcta, marcando la primera');
                        const primeraOpcion = document.querySelector('input[name="respuesta_correcta"]');
                        if (primeraOpcion) {
                            primeraOpcion.checked = true;
                            actualizarRespuestaCorrecta(primeraOpcion);
                        }
                    }
                }, 100);
                
                console.log(`✅ Se cargaron ${data.data.opciones.length} opciones existentes`);
            } else {
                console.log('⚠️ No hay opciones, agregando dos por defecto');
                agregarOpcion();
                agregarOpcion();
                
                // Marcar la primera como correcta por defecto
                setTimeout(() => {
                    const primeraOpcion = document.querySelector('input[name="respuesta_correcta"]');
                    if (primeraOpcion) {
                        primeraOpcion.checked = true;
                        actualizarRespuestaCorrecta(primeraOpcion);
                    }
                }, 100);
            }
            
            // ✅ SÉPTIMO: Abrir modal SIN limpiar
            abrirModalEdicion();
            
        } else {
            console.error('❌ Error en respuesta:', data);
            Swal.fire('Error', data.message || 'No se pudo cargar la pregunta', 'error');
        }
    })
    .catch(error => {
        console.error('❌ ERROR:', error);
        Swal.fire('Error', 'Error de conexión al cargar la pregunta', 'error');
    });
}

// ✅ NUEVA FUNCIÓN: Abrir modal para edición (sin limpiar)
function abrirModalEdicion() {
    document.getElementById('modalPregunta').style.display = 'block';
    document.getElementById('modalBackdrop').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// ✅ FUNCIÓN ORIGINAL: Abrir modal para crear (limpia todo)
function abrirModalPregunta() {
    limpiarModal();
    document.getElementById('modalPregunta').style.display = 'block';
    document.getElementById('modalBackdrop').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Función para confirmar eliminación - AGREGAR ESTA FUNCIÓN
function confirmarEliminarPregunta(id, enunciado) {
    Swal.fire({
        title: '¿Eliminar esta pregunta?',
        html: `
            <div style="text-align: left; margin: 20px 0;">
                <p><strong>Pregunta:</strong> "${enunciado}..."</p>
                <div style="background: #f8d7da; padding: 15px; border-radius: 8px; border-left: 4px solid #dc3545; margin: 15px 0;">
                    <p style="margin: 0; color: #721c24;"><strong>⚠️ Advertencia:</strong></p>
                    <ul style="margin: 10px 0 0 20px; color: #721c24;">
                        <li>Esta acción no se puede deshacer</li>
                        <li>Se eliminarán todas las opciones asociadas</li>
                        <li>Los exámenes que usen esta pregunta se verán afectados</li>
                    </ul>
                </div>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash-alt"></i> Sí, eliminar',
        cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
        customClass: {
            popup: 'swal-wide'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            eliminarPregunta(id);
        }
    });
}

// Función para eliminar pregunta - CORREGIDA
function eliminarPregunta(id) {
    fetch('preguntas.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=eliminar&id_pregunta=${id}`
    })
    .then(response => response.json())
    .then(data => {  // ✅ CORREGIDO: Agregado (data) =>
        if (data.success) {
            Swal.fire({
                title: '¡Eliminado!',
                text: data.message,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                // Recargar la página para actualizar la lista
                location.reload();
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Error al eliminar la pregunta', 'error');
    });
}

// Manejo del formulario - CORREGIDO PARA RADIO BUTTONS
document.getElementById('formPregunta').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // ✅ CORRECCIÓN: Validar radio button en lugar de checkboxes
    const radioCorrecta = document.querySelector('input[name="respuesta_correcta"]:checked');
    
    if (!radioCorrecta) {
        Swal.fire({
            title: 'Error de Validación',
            text: 'Debe seleccionar exactamente UNA respuesta como correcta',
            icon: 'error',
            confirmButtonText: 'Entendido'
        });
        return;
    }
    
    // Validar que haya al menos 2 opciones con texto
    const textareas = this.querySelectorAll('textarea[name*="opciones"][name*="texto"]');
    let opcionesConTexto = 0;
    textareas.forEach(textarea => {
        if (textarea.value.trim()) opcionesConTexto++;
    });
    
    if (opcionesConTexto < 2) {
        Swal.fire({
            title: 'Error de Validación',
            text: 'Debe tener al menos 2 opciones con texto',
            icon: 'error',
            confirmButtonText: 'Entendido'
        });
        return;
    }
    
    // Validar que la respuesta correcta tenga texto
    const indexCorrecta = radioCorrecta.value;
    const textareaCorrecta = document.querySelector(`textarea[name="opciones[${indexCorrecta}][texto]"]`);
    
    if (!textareaCorrecta || !textareaCorrecta.value.trim()) {
        Swal.fire({
            title: 'Error de Validación',
            text: 'La opción marcada como correcta debe tener texto',
            icon: 'error',
            confirmButtonText: 'Entendido'
        });
        return;
    }
    
    const formData = new FormData(this);
    const accion = document.getElementById('action').value;
    
    // ✅ AGREGAR: Campo de respuesta correcta al FormData
    formData.append('respuesta_correcta_index', indexCorrecta);
    
    // Mostrar loading
    Swal.fire({
        title: accion === 'crear' ? 'Creando pregunta...' : 'Actualizando pregunta...',
        html: `
            <div style="text-align: center;">
                <div style="margin-bottom: 16px;">
                    <i class="fas fa-check-circle" style="color: #28a745; font-size: 24px;"></i>
                </div>
                <p style="margin: 0; color: #495057;">
                    ${accion === 'crear' ? 'Creando pregunta con una respuesta correcta...' : 'Actualizando pregunta con una respuesta correcta...'}
                </p>
                <small style="color: #6c757d; font-size: 12px; display: block; margin-top: 8px;">
                    ✓ Solo una respuesta correcta por pregunta
                </small>
            </div>
        `,
        icon: 'info',
        allowOutsideClick: false,
        showConfirmButton: false,
        willOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('preguntas.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: '¡Éxito!',
                html: `
                    <div style="text-align: center;">
                        <div style="margin-bottom: 16px;">
                            <i class="fas fa-check-circle" style="color: #28a745; font-size: 32px;"></i>
                        </div>
                        <p style="margin: 0; color: #495057; font-weight: 500;">
                            ${data.message}
                        </p>
                        <small style="color: #6c757d; font-size: 12px; display: block; margin-top: 8px;">
                            ✓ Pregunta con una respuesta correcta
                        </small>
                    </div>
                `,
                icon: 'success',
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Error al procesar la solicitud', 'error');
    });
});

// Preview de imagen - AGREGAR ESTA FUNCIÓN
document.getElementById('imagen').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('preview_imagen');
    
    if (file) {
        // Validar tamaño (máximo 2MB)
        if (file.size > 2 * 1024 * 1024) {
            Swal.fire('Error', 'La imagen no puede ser mayor a 2MB', 'error');
            this.value = '';
            preview.innerHTML = '';
            return;
        }
        
        // Validar tipo
        if (!file.type.startsWith('image/')) {
            Swal.fire('Error', 'Solo se permiten archivos de imagen', 'error');
            this.value = '';
            preview.innerHTML = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `
                <img src="${e.target.result}" style="max-width: 150px; max-height: 150px; object-fit: cover; border-radius: 4px; border: 1px solid #dee2e6;" alt="Preview">
                <p style="margin: 4px 0 0 0; font-size: 12px; color: #6c757d;">
                    <i class="fas fa-eye"></i> Vista previa de la imagen
                </p>
            `;
        };
        reader.readAsDataURL(file);
    } else {
        preview.innerHTML = '';
    }
});

// Cerrar modal al hacer clic en el backdrop - AGREGAR ESTA FUNCIÓN
document.getElementById('modalBackdrop').addEventListener('click', cerrarModal);

// Cerrar modal con ESC - AGREGAR ESTA FUNCIÓN
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('modalPregunta').style.display === 'block') {
        cerrarModal();
    }
});

// Función para limpiar modal mejorada - ACTUALIZAR ESTA FUNCIÓN
function limpiarModal() {
    console.log('🧹 Limpiando modal para nueva pregunta...');
    
    // Resetear formulario
    document.getElementById('formPregunta').reset();
    
    // Configurar para nueva pregunta
    document.getElementById('modalTitulo').textContent = '✨ Nueva Pregunta';
    document.getElementById('action').value = 'crear';
    document.getElementById('id_pregunta').value = '';
    
    // Limpiar preview de imagen
    document.getElementById('preview_imagen').innerHTML = '';
    
    // Limpiar contenedor de opciones
    const contenedorOpciones = document.getElementById('contenedor_opciones');
    contenedorOpciones.innerHTML = '';
    contadorOpciones = 0;
    
    // Agregar dos opciones por defecto para nueva pregunta
    console.log('➕ Agregando opciones por defecto para nueva pregunta...');
    agregarOpcion(); // Opción 1 vacía
    agregarOpcion(); // Opción 2 vacía
    
    console.log('✅ Modal preparado para nueva pregunta');
}
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
    box-sizing: border-box;
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
    flex-wrap: wrap;
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

/* Modal personalizado */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 1050;
    overflow: auto;
}

.modal-dialog {
    margin: 50px auto;
    max-width: 800px;
    position: relative;
}

.modal-content {
    background: white;
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    overflow: hidden;
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
}

.modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--gris-oscuro);
}

.btn-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #6c757d;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

.btn-close:hover {
    color: var(--rojo-danger);
    background: #f8f9fa;
}

.modal-body {
    padding: 24px;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: #f8f9fa;
}

/* SweetAlert personalizado */
.swal-wide {
    width: 500px !important;
}

/* === FILTROS SIMPLES === */
.filters-simple {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.filters-header {
    margin-bottom: 20px;
}

.filters-header h3 {
    color: #333;
    font-size: 18px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}



.filters-row {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 20px;
    align-items: end;
}


.filter-group label {
    display: block;
    font-weight: 500;
    color: #555;
    margin-bottom: 8px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.filter-select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: inherit;
}

.filter-select:focus {
    outline: none;
    border-color: var(--azul-incatec);
    box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.1);
}

.filter-select:hover {
    border-color: #ced4da;
}

.filter-actions {
    display: flex;
    gap: 12px;
    align-items: center;
}

.btn-search {
    background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-size: 14px;
    white-space: nowrap;
}

.btn-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(33, 150, 243, 0.3);
}

.btn-clear {
    background: #f8f9fa;
    color: #6c757d;
    border: 2px solid #e9ecef;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    text-decoration: none;
    font-size: 14px;
    white-space: nowrap;
}

.btn-clear:hover {
    background: #e9ecef;
    border-color: #ced4da;
    color: #495057;
}

/* Información de Resultados */
.results-info {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #f1f3f4;
}

.results-count {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    color: #495057;
    flex-wrap: wrap;
}

.results-count i {
    color: var(--azul-incatec);
}

.filter-tag {
    background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Botón de Eliminar Mejorado */
.btn-delete {
    background: linear-gradient(135deg, #dc3545, #c82333) !important;
    color: white !important;
    border: none !important;
    padding: 8px 16px !important;
    border-radius: 6px !important;
    font-weight: 500 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: all 0.3s ease !important;
    font-size: 12px !important;
    text-decoration: none !important;
    box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2) !important;
}

.btn-delete:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4) !important;
    background: linear-gradient(135deg, #c82333, #a71e2a) !important;
}

.btn-delete:active {
    transform: translateY(0) !important;
    box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2) !important;
}

/* Responsive para Filtros Simples */
@media (max-width: 768px) {
    .filters-row {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .filter-actions {
        justify-content: stretch;
        flex-direction: column;
    }
    
    .btn-search,
    .btn-clear {
        justify-content: center;
        text-align: center;
    }
    
    .results-count {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .filter-tag {
        align-self: flex-start;
    }
}

@media (max-width: 480px) {
    .filters-simple {
        padding: 16px;
        margin-bottom: 20px;
    }
    
    .filters-header h3 {
        font-size: 16px;
    }
    
    .filter-select {
        padding: 10px 12px;
        font-size: 13px;
    }
    
    .btn-search,
    .btn-clear {
        padding: 10px 16px;
        font-size: 13px;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>