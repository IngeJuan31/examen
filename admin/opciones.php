<?php
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../controllers/verificar_sesion.php';
require_once '../controllers/permisos.php';
verificarPermiso('OPCIONES'); // Cambia el permiso según la vista

// Obtener preguntas
$preguntas = $pdo->query("SELECT * FROM preguntas ORDER BY id_pregunta")->fetchAll(PDO::FETCH_ASSOC);

// Insertar opción
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_pregunta = $_POST['id_pregunta'];
    $texto = trim($_POST['texto']);
    $es_correcta = !empty($_POST['es_correcta']) ? true : false;


    $stmt = $pdo->prepare("INSERT INTO opciones (id_pregunta, texto, es_correcta) VALUES (?, ?, ?)");
    $stmt->bindValue(1, $id_pregunta, PDO::PARAM_INT);
    $stmt->bindValue(2, $texto, PDO::PARAM_STR);
    $stmt->bindValue(3, $es_correcta, PDO::PARAM_BOOL);
    $stmt->execute();

}

// Listar opciones
$opciones = $pdo->query("
    SELECT o.*, p.enunciado
    FROM opciones o
    JOIN preguntas p ON p.id_pregunta = o.id_pregunta
    ORDER BY o.id_opcion
")->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>➕ Agregar Opción</h2>
<form method="POST">
    <label>Pregunta:</label><br>
    <select name="id_pregunta" required>
        <?php foreach ($preguntas as $p): ?>
            <option value="<?= $p['id_pregunta'] ?>"><?= htmlspecialchars($p['enunciado']) ?></option>
        <?php endforeach ?>
    </select><br>
    <input type="text" name="texto" placeholder="Texto de la opción" required><br>
    <label><input type="checkbox" name="es_correcta"> ¿Es correcta?</label><br>
    <button type="submit">Guardar</button>
</form>

<hr>

<h2>✅ Opciones</h2>
<ul>
<?php foreach ($opciones as $o): ?>
    <li><strong><?= htmlspecialchars($o['enunciado']) ?>:</strong> <?= htmlspecialchars($o['texto']) ?> <?= $o['es_correcta'] ? '✅' : '' ?></li>
<?php endforeach ?>
</ul>

<?php require_once '../includes/footer.php'; ?>
