<?php
require_once '../config/db.php';

if (!isset($_GET['id_pregunta']) || !is_numeric($_GET['id_pregunta'])) {
    echo '<div class="error-message"><i class="fas fa-exclamation-triangle"></i> ID de pregunta inválido</div>';
    exit;
}

$id_pregunta = (int)$_GET['id_pregunta'];

try {
    $stmt = $pdo->prepare("SELECT * FROM opciones WHERE id_pregunta = ? ORDER BY id_opcion");
    $stmt->execute([$id_pregunta]);
    $opciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($opciones)) {
        echo '<div class="no-options">
                <div class="no-options-icon">
                    <i class="fas fa-list-ul"></i>
                </div>
                <h4>No hay opciones aún</h4>
                <p>Agrega la primera opción usando el formulario de arriba</p>
                <small class="tip">💡 <strong>Tip:</strong> Necesitas al menos 2 opciones y marcar cuál es la correcta</small>
              </div>';
    } else {
        $tiene_correcta = false;
        $opciones_con_imagen = 0;
        foreach ($opciones as $opcion) {
            if ($opcion['es_correcta']) {
                $tiene_correcta = true;
            }
            if (!empty($opcion['imagen_url'])) {
                $opciones_con_imagen++;
            }
        }
        
        echo '<div class="options-header">
                <div class="options-stats">
                    <span class="stat-item">
                        <i class="fas fa-list-ul"></i> 
                        <strong>' . count($opciones) . '</strong> opciones
                    </span>';
        
        if ($opciones_con_imagen > 0) {
            echo '<span class="stat-item with-images">
                    <i class="fas fa-images"></i> 
                    <strong>' . $opciones_con_imagen . '</strong> con imagen
                  </span>';
        }
        
        echo '<span class="stat-item ' . ($tiene_correcta ? 'correct-set' : 'no-correct-set') . '">
                        <i class="fas ' . ($tiene_correcta ? 'fa-check-circle' : 'fa-exclamation-triangle') . '"></i>
                        ' . ($tiene_correcta ? 'Respuesta correcta definida' : 'Sin respuesta correcta') . '
                    </span>
                </div>
              </div>';
        
        echo '<div class="options-list">';
        
        foreach ($opciones as $index => $opcion) {
            $letra = chr(65 + $index); // A, B, C, D...
            echo '<div class="option-item ' . ($opcion['es_correcta'] ? 'correct-option' : '') . '">
                    <div class="option-content">
                        <div class="option-letter">' . $letra . '</div>
                        <div class="option-main">
                            <div class="option-text">' . htmlspecialchars($opcion['texto']) . '</div>';
            
            // Mostrar imagen si existe
            if (!empty($opcion['imagen_url'])) {
                echo '<div class="option-image">
                        <img src="../' . htmlspecialchars($opcion['imagen_url']) . '" 
                             alt="Imagen de la opción" 
                             class="option-img"
                             onclick="mostrarImagenCompleta(this.src)">
                        <div class="image-indicator">
                            <i class="fas fa-image"></i>
                        </div>
                      </div>';
            }
            
            echo '    </div>
                        <div class="option-badges">
                            ' . ($opcion['es_correcta'] ? 
                                '<span class="badge-correct"><i class="fas fa-check-circle"></i> Correcta</span>' : 
                                '<span class="badge-incorrect"><i class="fas fa-circle"></i> Opción</span>'
                            );
            
            // Badge adicional para imagen
            if (!empty($opcion['imagen_url'])) {
                echo '<span class="badge-image"><i class="fas fa-image"></i> Con imagen</span>';
            }
            
            echo '    </div>
                    </div>
                    <div class="option-actions">
                        <button type="button" class="btn-edit" 
                                onclick="editarOpcion(' . $opcion['id_opcion'] . ', \'' . addslashes(htmlspecialchars($opcion['texto'])) . '\', ' . ($opcion['es_correcta'] ? 'true' : 'false') . ', \'' . addslashes($opcion['imagen_url'] ?? '') . '\')" 
                                title="Editar opción">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" style="display: inline;" id="eliminarOpcion' . $opcion['id_opcion'] . '">
                            <input type="hidden" name="eliminar_opcion" value="1">
                            <input type="hidden" name="id_opcion" value="' . $opcion['id_opcion'] . '">
                            <input type="hidden" name="id_pregunta_modal" value="' . $id_pregunta . '">
                            <button type="button" class="btn-delete" 
                                    onclick="confirmarEliminar(' . $opcion['id_opcion'] . ', \'' . addslashes(htmlspecialchars($opcion['texto'])) . '\')" 
                                    title="Eliminar opción">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </div>
                  </div>';
        }
        
        echo '</div>';
        
        // Mostrar recomendaciones
        if (count($opciones) < 2) {
            echo '<div class="recommendation warning">
                    <i class="fas fa-info-circle"></i>
                    <strong>Recomendación:</strong> Agrega al menos 2 opciones para que la pregunta sea válida.
                  </div>';
        } elseif (!$tiene_correcta) {
            echo '<div class="recommendation error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Importante:</strong> Marca cuál es la respuesta correcta.
                  </div>';
        } else {
            echo '<div class="recommendation success">
                    <i class="fas fa-check-circle"></i>
                    <strong>¡Perfecto!</strong> Tu pregunta está completa y lista para usar.
                  </div>';
        }
    }
    
} catch (PDOException $e) {
    echo '<div class="error-message">
            <i class="fas fa-exclamation-triangle"></i> 
            Error al cargar opciones: ' . htmlspecialchars($e->getMessage()) . '
          </div>';
}
?>

<style>
/* Estilos para el contenido del modal de opciones */
.options-header {
    margin-bottom: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border-left: 4px solid #2196F3;
}

.options-stats {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #666;
}

.stat-item.with-images {
    color: #2196F3;
}

.stat-item.correct-set {
    color: #4CAF50;
}

.stat-item.no-correct-set {
    color: #f44336;
}

.no-options {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.no-options-icon {
    font-size: 48px;
    color: #ddd;
    margin-bottom: 15px;
}

.tip {
    color: #666;
    font-style: italic;
}

.options-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.option-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 15px;
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.option-item:hover {
    border-color: #2196F3;
    box-shadow: 0 2px 8px rgba(33, 150, 243, 0.1);
}

.correct-option {
    border-color: #4CAF50;
    background: #f8fff8;
}

.correct-option:hover {
    border-color: #4CAF50;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.2);
}

.option-content {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    flex: 1;
}

.option-letter {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #2196F3;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 14px;
    flex-shrink: 0;
}

.correct-option .option-letter {
    background: #4CAF50;
}

.option-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.option-text {
    font-size: 14px;
    line-height: 1.4;
    color: #333;
}

.option-image {
    position: relative;
    display: inline-block;
    max-width: 150px;
}

.option-img {
    max-width: 100%;
    max-height: 80px;
    border-radius: 6px;
    border: 2px solid #e0e0e0;
    cursor: pointer;
    transition: all 0.3s ease;
}

.option-img:hover {
    border-color: #2196F3;
    transform: scale(1.05);
}

.image-indicator {
    position: absolute;
    top: 5px;
    right: 5px;
    background: rgba(33, 150, 243, 0.9);
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
}

.option-badges {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    flex-wrap: wrap;
}

.badge-correct, .badge-incorrect, .badge-image {
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
    font-weight: 600;
}

.badge-correct {
    background: #e8f5e8;
    color: #2e7d32;
}

.badge-incorrect {
    background: #f5f5f5;
    color: #666;
}

.badge-image {
    background: #e3f2fd;
    color: #1976d2;
}

.option-actions {
    display: flex;
    gap: 8px;
    margin-left: 15px;
}

.btn-edit, .btn-delete {
    border: none;
    padding: 8px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
}

.btn-edit {
    background: #2196F3;
    color: white;
}

.btn-edit:hover {
    background: #1976D2;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(33, 150, 243, 0.3);
}

.btn-delete {
    background: #f44336;
    color: white;
}

.btn-delete:hover {
    background: #d32f2f;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(244, 67, 54, 0.3);
}

.recommendation {
    margin-top: 20px;
    padding: 15px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
}

.recommendation.warning {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.recommendation.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.recommendation.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.error-message {
    padding: 15px;
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Responsive */
@media (max-width: 768px) {
    .option-content {
        flex-direction: column;
        gap: 10px;
    }
    
    .option-letter {
        align-self: flex-start;
    }
    
    .options-stats {
        flex-direction: column;
        gap: 8px;
    }
    
    .option-image {
        max-width: 100%;
    }
}
</style>

<script>
function mostrarImagenCompleta(src) {
    // Crear modal para mostrar imagen completa
    const modal = document.createElement('div');
    modal.className = 'image-modal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        cursor: pointer;
    `;
    
    const img = document.createElement('img');
    img.src = src;
    img.style.cssText = `
        max-width: 90%;
        max-height: 90%;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    `;
    
    modal.appendChild(img);
    document.body.appendChild(modal);
    
    // Cerrar modal al hacer clic
    modal.onclick = function() {
        document.body.removeChild(modal);
    };
}

// Verificar que la función editarOpcion esté disponible
if (typeof editarOpcion !== 'function') {
    // Si no está disponible, crearla temporalmente
    window.editarOpcion = function(idOpcion, texto, esCorrecta, imagenUrl) {
        console.log('Función editarOpcion llamada desde get_opciones.php');
        // Intentar llamar a la función del padre
        if (window.parent && typeof window.parent.editarOpcion === 'function') {
            window.parent.editarOpcion(idOpcion, texto, esCorrecta, imagenUrl);
        } else {
            alert('Función de edición no disponible. Por favor recarga la página.');
        }
    };
}
</script>
 <?php require_once '../includes/footer.php'; ?>
