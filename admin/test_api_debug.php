<?php
// filepath: c:\xampp\htdocs\examen_ingreso\admin\test_api_debug.php
header('Content-Type: application/json');

try {
    $documento = $_GET['documento'] ?? '1003895357';
    
    // Probar conexión a la API externa
    $api_url = "https://sic.incatec.edu.co/api/matricula.php?documento=" . urlencode($documento);
    
    echo json_encode([
        'debug' => true,
        'api_url' => $api_url,
        'documento' => $documento,
        'timestamp' => date('Y-m-d H:i:s'),
        'test_message' => 'Debug API funcionando correctamente'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'debug' => true,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>