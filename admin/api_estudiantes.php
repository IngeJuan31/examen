<?php
// filepath: c:\xampp\htdocs\examen_ingreso\admin\api_estudiantes.php

// ✅ CONFIGURACIÓN DE RENDIMIENTO
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 15);

// Headers optimizados
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: private, max-age=300'); // Cache 5 minutos

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// ✅ CLASE PARA MANEJO DE CACHÉ
class CacheManager {
    private $cache_dir;
    private $cache_duration = 300; // 5 minutos
    
    public function __construct() {
        $this->cache_dir = __DIR__ . '/cache/';
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }
    
    public function get($key) {
        $file = $this->cache_dir . md5($key) . '.json';
        if (file_exists($file) && (time() - filemtime($file)) < $this->cache_duration) {
            return json_decode(file_get_contents($file), true);
        }
        return null;
    }
    
    public function set($key, $data) {
        $file = $this->cache_dir . md5($key) . '.json';
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    
    public function clear() {
        $files = glob($this->cache_dir . '*.json');
        foreach ($files as $file) {
            if (time() - filemtime($file) > $this->cache_duration) {
                unlink($file);
            }
        }
    }
}

// ✅ RATE LIMITING SIMPLE
class RateLimiter {
    private $requests_file;
    private $max_requests = 10; // 10 requests por minuto
    private $time_window = 60; // 1 minuto
    
    public function __construct() {
        $this->requests_file = __DIR__ . '/cache/requests.json';
    }
    
    public function checkLimit($ip) {
        $requests = [];
        if (file_exists($this->requests_file)) {
            $requests = json_decode(file_get_contents($this->requests_file), true) ?? [];
        }
        
        $current_time = time();
        $requests[$ip] = array_filter($requests[$ip] ?? [], function($timestamp) use ($current_time) {
            return ($current_time - $timestamp) < $this->time_window;
        });
        
        if (count($requests[$ip]) >= $this->max_requests) {
            return false;
        }
        
        $requests[$ip][] = $current_time;
        file_put_contents($this->requests_file, json_encode($requests));
        return true;
    }
}

// ✅ VALIDACIÓN OPTIMIZADA
function validateRequest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Solo se permite método GET', 405);
    }
    
    if (!isset($_GET['documento'])) {
        throw new Exception('Parámetro documento requerido', 400);
    }
    
    $documento = trim($_GET['documento']);
    
    if (empty($documento)) {
        throw new Exception('Documento no puede estar vacío', 400);
    }
    
    if (!preg_match('/^\d{7,15}$/', $documento)) {
        throw new Exception('Documento debe tener entre 7 y 15 dígitos', 400);
    }
    
    return $documento;
}

// ✅ FUNCIÓN OPTIMIZADA PARA CONSULTA API
function consultarAPIIncatec($documento) {
    $api_url = "https://sic.incatec.edu.co/api/matricula.php?documento=" . urlencode($documento);
    
    // Configuración optimizada
    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "timeout" => 8, // Reducido a 8 segundos
            "header" => [
                "User-Agent: ExamenIngreso/2.0",
                "Accept: application/json",
                "Accept-Encoding: gzip, deflate",
                "Connection: close"
            ]
        ],
        "ssl" => [
            "verify_peer" => false,
            "verify_peer_name" => false,
            "ciphers" => "HIGH:!aNULL:!MD5"
        ]
    ]);
    
    $start_time = microtime(true);
    $response = @file_get_contents($api_url, false, $context);
    $response_time = microtime(true) - $start_time;
    
    if ($response === false) {
        throw new Exception('Error al conectar con la API de INCATEC', 503);
    }
    
    // Log de rendimiento
    error_log("API Response Time: {$response_time}s for document: $documento");
    
    return $response;
}

// ✅ PROCESAMIENTO OPTIMIZADO DE DATOS
function procesarDatosEstudiante($data) {
    // Validaciones rápidas
    if (!isset($data['status']) || $data['status'] !== 'success') {
        $error_msg = $data['message'] ?? 'Error desconocido';
        throw new Exception("API INCATEC: $error_msg", 502);
    }
    
    if (!isset($data['data']['estudiante'])) {
        throw new Exception('Estructura de datos inválida', 502);
    }
    
    $estudiante = $data['data']['estudiante'];
    $matriculas = $data['data']['matriculas'] ?? [];
    
    // Validar campos requeridos de una vez
    $campos_requeridos = ['tipodoc', 'numid', 'nombre1', 'apellido1'];
    $campos_faltantes = array_filter($campos_requeridos, function($campo) use ($estudiante) {
        return !isset($estudiante[$campo]) || empty($estudiante[$campo]);
    });
    
    if (!empty($campos_faltantes)) {
        throw new Exception("Campos faltantes: " . implode(', ', $campos_faltantes), 502);
    }
    
    // Función optimizada para limpiar nombres
    $limpiar = function($texto) {
        return $texto ? ucwords(strtolower(trim($texto))) : '';
    };
    
    // Extraer datos con operador null coalescing
    $primer_nombre = $limpiar($estudiante['nombre1']);
    $segundo_nombre = $limpiar($estudiante['nombre2'] ?? '');
    $primer_apellido = $limpiar($estudiante['apellido1']);
    $segundo_apellido = $limpiar($estudiante['apellido2'] ?? '');
    
    // Construir nombre completo optimizado
    $nombres = array_filter([$primer_nombre, $segundo_nombre, $primer_apellido, $segundo_apellido]);
    $nombre_completo = implode(' ', $nombres);
    
    // Datos de matrícula
    $primera_matricula = $matriculas[0] ?? [];
    $programa = $primera_matricula['programa'] ?? '';
    $semestre = $primera_matricula['semestre'] ?? '';
    $jornada = $primera_matricula['jornada'] ?? '';
    
    // Generar usuario y correo optimizado
    $usuario_base = strtolower($primer_nombre . $primer_apellido);
    $usuario_base = preg_replace('/[^a-z0-9]/', '', $usuario_base);
    $usuario_base = strlen($usuario_base) < 3 ? 'user' . $estudiante['numid'] : $usuario_base;
    
    $nombre_correo = strtolower($primer_nombre . '.' . $primer_apellido);
    $nombre_correo = preg_replace('/[^a-z0-9\.]/', '', $nombre_correo);
    $correo = $nombre_correo . '@estudiante.incatec.edu.co';
    
    return [
        'nombre_completo' => $nombre_completo,
        'campos_individuales' => [
            'primer_nombre' => $primer_nombre,
            'segundo_nombre' => $segundo_nombre,
            'primer_apellido' => $primer_apellido,
            'segundo_apellido' => $segundo_apellido
        ],
        'identificacion' => $estudiante['numid'],
        'usuario' => $usuario_base,
        'clave_inicial' => $estudiante['numid'],
        'correo' => $correo,
        'programa' => $programa,
        'semestre' => $semestre,
        'jornada' => $jornada,
        'tipo_documento' => $estudiante['tipodoc'],
        'origen' => 'API INCATEC'
    ];
}

// ✅ MAIN EXECUTION
try {
    $start_time = microtime(true);
    
    // Inicializar componentes
    $cache = new CacheManager();
    $rateLimiter = new RateLimiter();
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Limpiar cache viejo
    $cache->clear();
    
    // Verificar rate limiting
    if (!$rateLimiter->checkLimit($user_ip)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Demasiadas peticiones. Intenta en 1 minuto.',
            'error_code' => 'RATE_LIMIT_EXCEEDED'
        ]);
        exit;
    }
    
    // Validar request
    $documento = validateRequest();
    
    // Verificar caché primero
    $cache_key = "estudiante_$documento";
    $cached_data = $cache->get($cache_key);
    
    if ($cached_data) {
        echo json_encode([
            'success' => true,
            'message' => 'Estudiante encontrado en INCATEC (caché)',
            'data' => $cached_data,
            'cached' => true
        ]);
        exit;
    }
    
    // Consultar API externa
    $response = consultarAPIIncatec($documento);
    
    // Procesar respuesta JSON
    $response_clean = trim($response);
    $response_clean = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $response_clean);
    
    $data = json_decode($response_clean, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Error al procesar respuesta JSON: ' . json_last_error_msg(), 502);
    }
    
    // Procesar datos del estudiante
    $estudiante_data = procesarDatosEstudiante($data);
    
    // Guardar en caché
    $cache->set($cache_key, $estudiante_data);
    
    // Calcular tiempo de respuesta
    $response_time = round((microtime(true) - $start_time) * 1000, 2);
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => 'Estudiante encontrado en INCATEC',
        'data' => $estudiante_data,
        'cached' => false,
        'response_time_ms' => $response_time
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $error_code = $e->getCode() ?: 500;
    http_response_code($error_code);
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'API_ERROR',
        'debug' => [
            'metodo' => $_SERVER['REQUEST_METHOD'],
            'documento' => $_GET['documento'] ?? 'no_enviado',
            'timestamp' => date('Y-m-d H:i:s'),
            'response_time_ms' => round((microtime(true) - ($start_time ?? microtime(true))) * 1000, 2)
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>