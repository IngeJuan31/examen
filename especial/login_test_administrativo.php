<?php
session_start();
require_once 'config/db.php';

// Simular login del usuario problemático
$_SESSION['participante_id'] = null;
$_SESSION['participante_nombre'] = null;

try {
    $stmt = $pdo->prepare("
        SELECT id_participante, nombre 
        FROM participantes 
        WHERE nombre = 'ADMINISTRATIVO' AND usuario = '123'
    ");
    $stmt->execute();
    $participante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($participante) {
        $_SESSION['participante_id'] = $participante['id_participante'];
        $_SESSION['participante_nombre'] = $participante['nombre'];
        
        // Redirigir a ver resultado
        header('Location: ver_resultado.php');
        exit;
    } else {
        echo "❌ Usuario no encontrado";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
