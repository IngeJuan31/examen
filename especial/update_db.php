<?php
require_once 'config/db.php';

// Crear rehabilitación por competencia para LENGUA CASTELLANA
$stmt = $pdo->prepare('
    INSERT INTO rehabilitaciones_competencia 
    (participante_id, competencia_id, competencia_nombre, admin_id, admin_nombre, intento_anterior, motivo, estado, fecha_rehabilitacion)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
');

$stmt->execute([
    40, // participante_id
    3,  // competencia_id de LENGUA CASTELLANA
    'LENGUA CASTELLANA',
    1,  // admin_id (asumiendo que existe)
    'Administrador',
    3,  // último intento
    'Rehabilitación automática por competencia específica reprobada',
    'ACTIVA'
]);

// Cambiar estado del participante
$stmt2 = $pdo->prepare('UPDATE participantes SET estado_examen = ? WHERE id_participante = ?');
$stmt2->execute(['REHABILITADO', 40]);

echo "Rehabilitación creada y estado actualizado\n";
?>
