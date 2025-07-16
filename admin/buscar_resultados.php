<?php
require_once '../config/db.php';
require_once '../controllers/verificar_sesion.php';
require_once '../controllers/permisos.php';
verificarPermiso('BUSCAR RESULTADOS'); // Cambia el permiso según la vista


// *** MANEJAR PETICIONES AJAX PRIMERO ***
// Función para obtener historial detallado (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['obtener_historial'])) {
    $participante_id = (int)$_POST['participante_id'];
    
    // Validar ID del participante
    if ($participante_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'ID de participante inválido'
        ]);
        exit;
    }
    
    try {
        // Obtener datos del participante
        $stmt_participante = $pdo->prepare("
            SELECT nombre, identificacion, usuario, fecha_registro, estado_examen 
            FROM participantes 
            WHERE id_participante = ?
        ");
        $stmt_participante->execute([$participante_id]);
        $participante = $stmt_participante->fetch(PDO::FETCH_ASSOC);
        
        // Obtener historial de exámenes con detalles por competencia
        $stmt = $pdo->prepare("
            SELECT 
                he.*,
                TO_CHAR(he.fecha_realizacion, 'DD/MM/YYYY HH24:MI:SS') as fecha_formato
            FROM historial_examenes he 
            WHERE he.participante_id = ?
            ORDER BY he.intento_numero DESC
        ");
        $stmt->execute([$participante_id]);
        $historial_examenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Para cada examen, obtener el detalle de competencias con estado final
        foreach ($historial_examenes as &$examen) {
            $stmt_comp = $pdo->prepare("
                SELECT 
                    hc.*,
                    ROUND(hc.porcentaje, 2) as porcentaje_redondeado,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM rehabilitaciones_competencia rc 
                            WHERE rc.participante_id = ? 
                            AND rc.competencia_id = hc.competencia_id 
                            AND rc.fecha_utilizacion IS NOT NULL
                            AND rc.fecha_utilizacion <= ?
                        ) THEN 'APROBADO'
                        ELSE hc.estado
                    END as estado_final,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM rehabilitaciones_competencia rc 
                            WHERE rc.participante_id = ? 
                            AND rc.competencia_id = hc.competencia_id 
                            AND rc.fecha_utilizacion IS NOT NULL
                            AND rc.fecha_utilizacion <= ?
                        ) THEN 'REHABILITADO'
                        ELSE 'ORIGINAL'
                    END as origen_aprobacion
                FROM historial_competencias hc 
                WHERE hc.historial_id = ?
                ORDER BY hc.competencia_id
            ");
            $stmt_comp->execute([
                $participante_id, 
                $examen['fecha_realizacion'],
                $participante_id, 
                $examen['fecha_realizacion'],
                $examen['id_historial']
            ]);
            $examen['competencias'] = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // *** NUEVA SECCIÓN: Obtener TODAS las competencias realizadas por el participante ***
        $stmt_todas_competencias = $pdo->prepare("
            SELECT 
                hc.competencia_id,
                hc.competencia_nombre,
                COUNT(hc.id_historial_competencia) as total_intentos,
                MAX(hc.porcentaje) as mejor_resultado,
                MIN(hc.porcentaje) as peor_resultado,
                AVG(hc.porcentaje) as promedio_resultado,
                SUM(hc.respuestas_correctas) as total_correctas,
                SUM(hc.total_preguntas) as total_preguntas_realizadas,
                -- Estado final considerando rehabilitaciones
                CASE 
                    WHEN EXISTS (
                        SELECT 1 FROM rehabilitaciones_competencia rc 
                        WHERE rc.participante_id = ? 
                        AND rc.competencia_id = hc.competencia_id 
                        AND rc.fecha_utilizacion IS NOT NULL
                    ) THEN 'APROBADO'
                    WHEN MAX(hc.porcentaje) >= 70 THEN 'APROBADO'
                    ELSE 'REPROBADO'
                END as estado_final_global,
                -- Origen de la aprobación
                CASE 
                    WHEN EXISTS (
                        SELECT 1 FROM rehabilitaciones_competencia rc 
                        WHERE rc.participante_id = ? 
                        AND rc.competencia_id = hc.competencia_id 
                        AND rc.fecha_utilizacion IS NOT NULL
                    ) THEN 'REHABILITADO'
                    WHEN MAX(hc.porcentaje) >= 70 THEN 'DIRECTO'
                    ELSE 'NINGUNO'
                END as origen_aprobacion_global,
                -- Información de rehabilitación si existe
                (SELECT TO_CHAR(rc.fecha_rehabilitacion, 'DD/MM/YYYY') 
                 FROM rehabilitaciones_competencia rc 
                 WHERE rc.participante_id = ? AND rc.competencia_id = hc.competencia_id 
                 AND rc.fecha_utilizacion IS NOT NULL LIMIT 1) as fecha_rehabilitacion,
                -- Intentos donde se realizó esta competencia (SIN DISTINCT y ORDER BY)
                STRING_AGG(he.intento_numero::text, ', ') as intentos_realizados,
                -- **NUEVO**: Cuántas rehabilitaciones tuvo para esta competencia
                (SELECT COUNT(*) 
                 FROM rehabilitaciones_competencia rc 
                 WHERE rc.participante_id = ? AND rc.competencia_id = hc.competencia_id) as total_rehabilitaciones_competencia,
                -- **NUEVO**: Cuántas rehabilitaciones utilizó para esta competencia
                (SELECT COUNT(*) 
                 FROM rehabilitaciones_competencia rc 
                 WHERE rc.participante_id = ? AND rc.competencia_id = hc.competencia_id 
                 AND rc.fecha_utilizacion IS NOT NULL) as rehabilitaciones_utilizadas_competencia
            FROM historial_competencias hc
            INNER JOIN historial_examenes he ON hc.historial_id = he.id_historial
            WHERE he.participante_id = ?
            GROUP BY hc.competencia_id, hc.competencia_nombre
            ORDER BY hc.competencia_id
        ");
        $stmt_todas_competencias->execute([
            $participante_id, // Para el primer EXISTS
            $participante_id, // Para el segundo CASE
            $participante_id, // Para fecha_rehabilitacion
            $participante_id, // Para total_rehabilitaciones_competencia
            $participante_id, // Para rehabilitaciones_utilizadas_competencia
            $participante_id  // Para el WHERE principal
        ]);
        $todas_las_competencias = $stmt_todas_competencias->fetchAll(PDO::FETCH_ASSOC);
        
        // *** CALCULAR ESTADO GLOBAL DEL PARTICIPANTE ***
        $total_competencias_unicas = count($todas_las_competencias);
        $competencias_aprobadas_global = count(array_filter($todas_las_competencias, fn($c) => $c['estado_final_global'] === 'APROBADO'));
        $estado_global_participante = ($competencias_aprobadas_global === $total_competencias_unicas && $total_competencias_unicas > 0) ? 'APROBADO' : 'REPROBADO';
        
        // Obtener rehabilitaciones por competencia
        $stmt_rehab = $pdo->prepare("
            SELECT 
                rc.*,
                TO_CHAR(rc.fecha_rehabilitacion, 'DD/MM/YYYY HH24:MI') as fecha_formato,
                CASE WHEN rc.fecha_utilizacion IS NOT NULL THEN 'COMPLETADA' ELSE 'PENDIENTE' END as estado_texto,
                TO_CHAR(rc.fecha_utilizacion, 'DD/MM/YYYY HH24:MI') as fecha_completada_formato,
                CASE WHEN rc.fecha_utilizacion IS NOT NULL THEN true ELSE false END as completada
            FROM rehabilitaciones_competencia rc 
            WHERE rc.participante_id = ?
            ORDER BY rc.fecha_rehabilitacion DESC
        ");
        $stmt_rehab->execute([$participante_id]);
        $rehabilitaciones = $stmt_rehab->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener estadísticas generales
        $estadisticas = [
            'total_examenes' => count($historial_examenes),
            'examenes_aprobados' => count(array_filter($historial_examenes, fn($h) => $h['estado'] === 'APROBADO')),
            'examenes_reprobados' => count(array_filter($historial_examenes, fn($h) => $h['estado'] === 'REPROBADO')),
            'total_rehabilitaciones' => count($rehabilitaciones),
            'rehabilitaciones_pendientes' => count(array_filter($rehabilitaciones, fn($r) => !$r['completada'])),
            'rehabilitaciones_completadas' => count(array_filter($rehabilitaciones, fn($r) => $r['completada'])),
            'promedio_puntaje' => count($historial_examenes) > 0 ? array_sum(array_column($historial_examenes, 'porcentaje')) / count($historial_examenes) : 0
        ];
        
        // Devolver JSON
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'participante' => $participante,
            'historial_examenes' => $historial_examenes,
            'rehabilitaciones' => $rehabilitaciones,
            'estadisticas' => $estadisticas,
            // *** NUEVOS DATOS GLOBALES ***
            'todas_las_competencias' => $todas_las_competencias,
            'resumen_global' => [
                'total_competencias_unicas' => $total_competencias_unicas,
                'competencias_aprobadas_global' => $competencias_aprobadas_global,
                'competencias_reprobadas_global' => $total_competencias_unicas - $competencias_aprobadas_global,
                'estado_global_participante' => $estado_global_participante,
                'competencias_por_rehabilitacion' => count(array_filter($todas_las_competencias, fn($c) => $c['origen_aprobacion_global'] === 'REHABILITADO')),
                'competencias_por_resultado_directo' => count(array_filter($todas_las_competencias, fn($c) => $c['origen_aprobacion_global'] === 'DIRECTO')),
                'promedio_general' => $total_competencias_unicas > 0 ? array_sum(array_column($todas_las_competencias, 'mejor_resultado')) / $total_competencias_unicas : 0
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

// *** CONTINUAR CON LA LÓGICA NORMAL ***
require_once '../includes/header.php';


$resultados_busqueda = [];
$criterio_busqueda = '';
$alerta = null;

// Procesar búsqueda
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar'])) {
    $criterio = trim($_POST['criterio_busqueda']);
    
    if (!empty($criterio)) {
        try {
            // Buscar por nombre o cédula incluyendo historial
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    p.id_participante,
                    p.nombre,
                    p.identificacion as cedula,
                    p.usuario,
                    p.fecha_registro,
                    p.estado_examen,
                    p.intentos_permitidos,
                    ae.nivel_dificultad,
                    ae.fecha_asignacion,
                    r.porcentaje as puntaje_total,
                    r.fecha_realizacion as fecha_examen,
                    (SELECT COUNT(*) 
                     FROM respuestas resp 
                     WHERE resp.id_participante = p.id_participante) as total_respuestas,
                    (SELECT COUNT(*) 
                     FROM historial_examenes he 
                     WHERE he.participante_id = p.id_participante) as total_intentos,
                    (SELECT MAX(intento_numero) 
                     FROM historial_examenes he 
                     WHERE he.participante_id = p.id_participante) as ultimo_intento,
                    (SELECT COUNT(*) 
                     FROM rehabilitaciones_competencia rc 
                     WHERE rc.participante_id = p.id_participante) as total_rehabilitaciones
                FROM participantes p
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                LEFT JOIN resultados r ON p.id_participante = r.participante_id
                WHERE 
                    LOWER(p.nombre) LIKE LOWER(?) OR 
                    p.identificacion LIKE ?
                ORDER BY p.nombre ASC
            ");
            
            $busqueda = '%' . $criterio . '%';
            $stmt->execute([$busqueda, $busqueda]);
            $resultados_busqueda = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $criterio_busqueda = $criterio;
            
            if (empty($resultados_busqueda)) {
                $alerta = ['tipo' => 'info', 'mensaje' => 'No se encontraron participantes que coincidan con el criterio de búsqueda.'];
            }
            
        } catch (Exception $e) {
            $alerta = ['tipo' => 'error', 'mensaje' => 'Error en la búsqueda: ' . $e->getMessage()];
        }
    } else {
        $alerta = ['tipo' => 'warning', 'mensaje' => 'Por favor ingresa un criterio de búsqueda.'];
    }
}

// Obtener estadísticas generales
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_participantes,
            COUNT(CASE WHEN r.id_resultado IS NOT NULL THEN 1 END) as con_resultados,
            COUNT(CASE WHEN ae.id_asignacion IS NOT NULL THEN 1 END) as con_examen_asignado,
            AVG(CASE WHEN r.porcentaje IS NOT NULL THEN r.porcentaje END) as promedio_puntaje
        FROM participantes p
        LEFT JOIN resultados r ON p.id_participante = r.participante_id
        LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
    ");
    $estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $estadisticas = ['total_participantes' => 0, 'con_resultados' => 0, 'con_examen_asignado' => 0, 'promedio_puntaje' => 0];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Búsqueda de Resultados - Admin INCATEC</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --azul-incatec: #2196F3;
            --verde-success: #4CAF50;
            --rojo-danger: #f44336;
            --naranja-warning: #ff9800;
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
            background: var(--gris-suave);
            color: var(--gris-oscuro);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: var(--sombra-suave);
        }

        .header h1 {
            font-size: 2.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .nav-links {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: var(--sombra-suave);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .nav-link {
            background: var(--gris-suave);
            color: var(--gris-oscuro);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .nav-link:hover {
            background: var(--azul-incatec);
            color: white;
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--azul-incatec);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--sombra-suave);
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--sombra-hover);
        }

        .stat-icon {
            background: var(--azul-incatec);
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 15px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--azul-incatec);
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .search-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--sombra-suave);
            margin-bottom: 30px;
        }

        .search-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 300px;
            padding: 15px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--azul-incatec);
            box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.1);
        }

        .btn-search {
            background: var(--azul-incatec);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-search:hover {
            background: #1976d2;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3);
        }

        .results-section {
            background: white;
            border-radius: 12px;
            box-shadow: var(--sombra-suave);
            overflow: hidden;
        }

        .results-header {
            background: var(--azul-incatec);
            color: white;
            padding: 20px 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
        }

        .results-table th {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--azul-incatec);
            font-size: 0.9rem;
            color: var(--gris-oscuro);
        }

        .results-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: top;
        }

        .results-table tr:hover {
            background: linear-gradient(135deg, #f8f9fa, #f5f7fa);
            transform: scale(1.001);
            transition: all 0.2s ease;
        }

        /* Tabla responsiva con cards en móvil */
        @media (max-width: 1200px) {
            .results-table, .results-table thead, .results-table tbody, .results-table th, .results-table td, .results-table tr {
                display: block;
            }
            
            .results-table thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            
            .results-table tr {
                background: white;
                border: 1px solid #e0e0e0;
                border-radius: 12px;
                margin-bottom: 15px;
                padding: 20px;
                box-shadow: var(--sombra-suave);
                transition: all 0.3s ease;
            }
            
            .results-table tr:hover {
                box-shadow: var(--sombra-hover);
                transform: translateY(-2px);
            }
            
            .results-table td {
                border: none;
                border-bottom: 1px solid #f0f0f0;
                position: relative;
                padding: 12px 0;
                padding-left: 45%;
                text-align: left;
            }
            
            .results-table td:before {
                content: attr(data-label);
                position: absolute;
                left: 0;
                width: 40%;
                padding-right: 10px;
                white-space: nowrap;
                font-weight: 600;
                color: var(--azul-incatec);
                font-size: 0.9rem;
            }
            
            .results-table td:last-child {
                border-bottom: none;
            }
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
            display: inline-block;
        }

        .status-completado {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-pendiente {
            background: #fff3e0;
            color: #ef6c00;
        }

        .status-sin-asignar {
            background: #ffebee;
            color: #c62828;
        }

        .puntaje-badge {
            background: var(--azul-incatec);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .btn-ver-resultado {
            background: var(--verde-success);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }

        .btn-ver-resultado:hover {
            background: #388e3c;
            transform: translateY(-1px);
        }

        .no-results {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-results i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-info {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 4px solid #2196f3;
        }

        .alert-warning {
            background: #fff3e0;
            color: #ef6c00;
            border-left: 4px solid #ff9800;
        }

        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #f44336;
        }

        /* Estilos para el modal mejorado */
        .modal {
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalAppear 0.3s ease-out;
            max-width: 95%;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        @keyframes modalAppear {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .timeline-container {
            position: relative;
        }

        .timeline-item {
            margin-bottom: 30px;
        }

        .timeline-marker {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            border: 3px solid white;
        }

        .timeline-content {
            transition: all 0.3s ease;
        }

        .timeline-content:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15) !important;
        }

        .badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .bg-success {
            background: linear-gradient(135deg, #28a745, #20c997) !important;
            color: white;
        }

        .bg-danger {
            background: linear-gradient(135deg, #dc3545, #e74c3c) !important;
            color: white;
        }

        .bg-warning {
            background: linear-gradient(135deg, #ffc107, #ff9800) !important;
            color: #333;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
            border: 0.3em solid rgba(0, 123, 255, 0.2);
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border 0.75s linear infinite;
        }

        @keyframes spinner-border {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Estilos adicionales para el historial mejorado */
        @media (max-width: 768px) {
            .modal-content {
                max-width: 98% !important;
                margin: 10px;
            }
            
            /* Timeline responsivo en móviles */
            .timeline-item {
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            
            .timeline-item > div:last-child {
                width: 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
        }

        /* Animaciones para las tarjetas del historial */
        .timeline-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .timeline-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15) !important;
        }

        /* Efectos de progreso */
        .progress-bar-animated {
            background-size: 1rem 1rem;
            animation: progress-bar-stripes 1s linear infinite;
        }

        @keyframes progress-bar-stripes {
            0% { background-position: 1rem 0; }
            100% { background-position: 0 0; }
        }

        /* Estilos para los iconos decorativos */
        .icon-float {
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        /* Mejoras para el modal */
        .modal-header-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
        }

        .modal-header-gradient::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM36 0V4h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
            opacity: 0.1;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .visually-hidden {
            position: absolute !important;
            width: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: -1px !important;
            overflow: hidden !important;
            clip: rect(0, 0, 0, 0) !important;
            white-space: nowrap !important;
            border: 0 !important;
        }

        /* Responsive para móviles */
        @media (max-width: 768px) {
            .modal-content {
                max-width: 98%;
                max-height: 95vh;
                margin: 10px;
            }
            
            .timeline-item {
                flex-direction: column;
            }
            
            .timeline-marker {
                margin-right: 0;
                margin-bottom: 15px;
                align-self: center;
            }
            
            .timeline-content {
                margin-left: 0;
            }
            
            .row {
                margin: 0 !important;
            }
            
            .col-md-3, .col-md-4, .col-md-6 {
                margin-bottom: 10px;
            }
            
            .container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.8rem;
            }
            
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-input {
                min-width: auto;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .results-table {
                font-size: 0.9rem;
            }
            
            .results-table th,
            .results-table td {
                padding: 10px 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-search"></i>
                Búsqueda de Resultados
            </h1>
            <p>Panel de administración para buscar y revisar resultados de participantes</p>
        </div>

 

        <!-- Formulario de Búsqueda -->
        <div class="search-section">
            <h2 style="margin-bottom: 20px; color: var(--azul-incatec);">
                <i class="fas fa-search"></i>
                Buscar Participante
            </h2>
            <form method="POST" class="search-form">
        <input type="text" 
               name="criterio_busqueda" 
               class="search-input" 
               placeholder="Buscar por nombre o cédula..." 
               value="<?= htmlspecialchars($criterio_busqueda) ?>"
               required>
                <button type="submit" name="buscar" class="btn-search">
                    <i class="fas fa-search"></i>
                    Buscar
                </button>
            </form>
        </div>

        <!-- Alertas -->
        <?php if ($alerta): ?>
            <div class="alert alert-<?= $alerta['tipo'] ?>">
                <i class="fas fa-<?= $alerta['tipo'] === 'error' ? 'exclamation-triangle' : ($alerta['tipo'] === 'warning' ? 'exclamation-circle' : 'info-circle') ?>"></i>
                <?= htmlspecialchars($alerta['mensaje']) ?>
            </div>
        <?php endif; ?>

        <!-- Resultados de Búsqueda -->
        <?php if (!empty($resultados_busqueda)): ?>
            <div class="results-section">
                <div class="results-header">
                    <i class="fas fa-list"></i>
                    <h3>Resultados de Búsqueda (<?= count($resultados_busqueda) ?> encontrado<?= count($resultados_busqueda) != 1 ? 's' : '' ?>)</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>Participante</th>
                                <th>Cédula</th>
                                <th>Usuario</th>
                                <th>Estado Sistema</th>
                                <th>Nivel Asignado</th>
                                <th>Estado Examen</th>
                                <th>Puntaje</th>
                                <th>Fecha Examen</th>
                                <th>Historial</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados_busqueda as $participante): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($participante['nombre']) ?></strong><br>
                                        <small style="color: #666;">Registro: <?= date('d/m/Y', strtotime($participante['fecha_registro'])) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($participante['cedula']) ?></td>
                                    <td>
                                        <code><?= htmlspecialchars($participante['usuario']) ?></code>
                                    </td>
                                    <td>
                                        <?php 
                                        $estado_sistema = $participante['estado_examen'] ?: 'NUEVO';
                                        $color_estado = $estado_sistema === 'APROBADO' ? 'status-completado' : 
                                                       ($estado_sistema === 'REPROBADO' ? 'status-sin-asignar' : 
                                                       ($estado_sistema === 'REHABILITADO' ? 'status-pendiente' : 'status-sin-asignar'));
                                        ?>
                                        <span class="status-badge <?= $color_estado ?>">
                                            <?= $estado_sistema ?>
                                        </span>
                                        <?php if ($participante['total_rehabilitaciones'] > 0): ?>
                                            <br><small style="color: #ff9800;">
                                                <i class="fas fa-redo"></i> <?= $participante['total_rehabilitaciones'] ?> rehabilitación(es)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($participante['nivel_dificultad']): ?>
                                            <span class="status-badge status-completado">
                                                <?= ucfirst($participante['nivel_dificultad']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-sin-asignar">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($participante['puntaje_total'] !== null): ?>
                                            <span class="status-badge status-completado">
                                                <i class="fas fa-check-circle"></i> Completado
                                            </span>
                                        <?php elseif ($participante['total_respuestas'] > 0): ?>
                                            <span class="status-badge status-pendiente">
                                                <i class="fas fa-clock"></i> En progreso
                                            </span>
                                        <?php elseif ($participante['nivel_dificultad']): ?>
                                            <span class="status-badge status-pendiente">
                                                <i class="fas fa-hourglass-start"></i> Pendiente
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-sin-asignar">
                                                <i class="fas fa-times-circle"></i> Sin examen
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($participante['puntaje_total'] !== null): ?>
                                            <span class="puntaje-badge"><?= number_format($participante['puntaje_total'], 1) ?>%</span>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($participante['fecha_examen']): ?>
                                            <?= date('d/m/Y H:i', strtotime($participante['fecha_examen'])) ?>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($participante['total_intentos'] > 0): ?>
                                            <div style="text-align: center;">
                                                <div class="status-badge" style="background: #e3f2fd; color: #1565c0; margin-bottom: 5px;">
                                                    <i class="fas fa-history"></i> <?= $participante['total_intentos'] ?> intento(s)
                                                </div>
                                                <?php if ($participante['ultimo_intento']): ?>
                                                    <small style="color: #666;">
                                                        Último: #<?= $participante['ultimo_intento'] ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #999; font-style: italic;">Sin historial</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px; flex-direction: column;">
                                            <?php if ($participante['puntaje_total'] !== null): ?>
                                                <a href="ver_resultado_admin.php?id=<?= $participante['id_participante'] ?>" class="btn-ver-resultado" target="_blank">
                                                    <i class="fas fa-eye"></i>
                                                    Ver Resultado
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($participante['total_intentos'] > 0): ?>
                                                <button onclick="verHistorialDetallado(<?= $participante['id_participante'] ?>, '<?= htmlspecialchars($participante['nombre']) ?>')" 
                                                        class="btn-ver-resultado" style="background: #ff9800;">
                                                    <i class="fas fa-history"></i>
                                                    Ver Historial
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($participante['estado_examen'] === 'REPROBADO'): ?>
                                                <a href="rehabilitaciones.php?participante=<?= $participante['id_participante'] ?>" 
                                                   class="btn-ver-resultado" style="background: #2196f3;" 
                                                   title="Ver historial - Rehabilitaciones automáticas">
                                                    <i class="fas fa-history"></i>
                                                    Ver Historial
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif (!empty($criterio_busqueda)): ?>
            <div class="no-results">
                <i class="fas fa-search-minus"></i>
                <h3>No se encontraron resultados</h3>
                <p>No hay participantes que coincidan con "<strong><?= htmlspecialchars($criterio_busqueda) ?></strong>"</p>
                <p>Intenta con otro nombre o cédula</p>
            </div>
        <?php else: ?>
            <div class="no-results">
                <i class="fas fa-search"></i>
                <h3>Búsqueda de Participantes</h3>
                <p>Usa el formulario de arriba para buscar participantes por nombre o cédula</p>
                <p>Las estadísticas generales se muestran arriba</p>
            </div>
        <?php endif; ?>

        <!-- Modal de Historial Detallado (Versión Mejorada) -->
        <div id="modalHistorial" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 95%; max-height: 90vh; overflow: hidden; border-radius: 15px; box-shadow: 0 20px 50px rgba(0,0,0,0.3);">
                <div class="modal-header modal-header-gradient" style="color: white; padding: 25px; position: relative;">
                    <h3 id="modalHistorialTitulo" style="margin: 0; font-size: 1.5rem; display: flex; align-items: center; gap: 10px; position: relative; z-index: 2;">
                        <i class="fas fa-user-clock icon-float"></i>
                        <span id="modalHistorialNombre">Historial de Exámenes</span>
                    </h3>
                    <button onclick="cerrarModalHistorial()" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 1.2rem; cursor: pointer; position: absolute; right: 20px; top: 50%; transform: translateY(-50%); padding: 8px 12px; border-radius: 8px; transition: all 0.3s ease; z-index: 2;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <!-- Estadísticas Generales -->
                <div id="modalEstadisticas" style="background: #f8f9fa; padding: 20px; border-bottom: 1px solid #e9ecef;">
                    <!-- Contenido de estadísticas -->
                </div>
                
                <div class="modal-body" style="padding: 0; max-height: calc(90vh - 200px); overflow-y: auto;" id="modalHistorialContenido">
                    <div style="display: flex; justify-content: center; align-items: center; height: 200px;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- Cierra container principal -->

    <script>
        // Auto-focus en el campo de búsqueda
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('.search-input');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });

        // Función para ver historial detallado
        function verHistorialDetallado(participanteId, nombre) {
            // Mostrar modal con loading
            document.getElementById('modalHistorial').style.display = 'flex';
            document.getElementById('modalHistorialNombre').textContent = nombre;
            
            // Mostrar loading
            document.getElementById('modalHistorialContenido').innerHTML = `
                <div style="display: flex; justify-content: center; align-items: center; height: 200px;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                </div>
            `;
            
            // Hacer petición AJAX
            fetch('buscar_resultados.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `obtener_historial=1&participante_id=${participanteId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    mostrarHistorialCompleto(data);
                } else {
                    mostrarError(data.error || 'Error al cargar el historial');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarError('Error de conexión al cargar el historial');
            });
        }
        
        function mostrarHistorialCompleto(data) {
            const examenes = data.historial_examenes || [];
            const rehabilitaciones = data.rehabilitaciones || [];
            const resumenGlobal = data.resumen_global || {};
            
            // NUEVO DISEÑO - Estadísticas compactas con paleta principal
            const estadisticasHtml = `
                <div style="background: linear-gradient(135deg, var(--azul-incatec), #1976d2); color: white; padding: 20px; position: relative;">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <h4 style="margin: 0; font-size: 1.3rem; font-weight: 600;">
                            <i class="fas fa-user-graduate" style="margin-right: 10px;"></i>
                            Estado del Participante
                        </h4>
                        <div style="
                            background: ${resumenGlobal.estado_global_participante === 'APROBADO' ? 'var(--verde-success)' : 'var(--rojo-danger)'};
                            color: white;
                            border-radius: 20px;
                            padding: 8px 20px;
                            margin: 15px auto;
                            display: inline-block;
                            font-weight: 700;
                            font-size: 1rem;
                            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                        ">
                            ${resumenGlobal.estado_global_participante === 'APROBADO' ? '✅ APROBADO' : '❌ REPROBADO'}
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; text-align: center;">
                        <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.2);">
                            <div style="font-size: 1.8rem; font-weight: 700; margin-bottom: 5px;">${resumenGlobal.total_competencias_unicas || 0}</div>
                            <div style="font-size: 0.85rem; opacity: 0.9;">Competencias Totales</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.2);">
                            <div style="font-size: 1.8rem; font-weight: 700; margin-bottom: 5px; color: #4CAF50;">${resumenGlobal.competencias_aprobadas_global || 0}</div>
                            <div style="font-size: 0.85rem; opacity: 0.9;">Aprobadas</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.2);">
                            <div style="font-size: 1.8rem; font-weight: 700; margin-bottom: 5px;">${(resumenGlobal.promedio_general || 0).toFixed(0)}%</div>
                            <div style="font-size: 0.85rem; opacity: 0.9;">Promedio General</div>
                        </div>
                    </div>
                    
                    ${rehabilitaciones.length > 0 ? `
                        <div style="margin-top: 15px; text-align: center;">
                            <div style="background: rgba(255,255,255,0.2); border-radius: 15px; padding: 8px 15px; display: inline-block; font-size: 0.9rem;">
                                <i class="fas fa-redo" style="margin-right: 6px;"></i>
                                <strong>${rehabilitaciones.length}</strong> rehabilitaciones otorgadas
                                <span style="margin-left: 8px; opacity: 0.8;">
                                    (${rehabilitaciones.filter(r => r.completada).length} utilizadas)
                                </span>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
            document.getElementById('modalEstadisticas').innerHTML = estadisticasHtml;
            
            // NUEVO DISEÑO - Historial más compacto y claro
            let contenidoHtml = '';
            
            if (examenes.length > 0) {
                contenidoHtml += `
                    <div style="padding: 25px; background: white;">
                        <div style="display: flex; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--gris-suave);">
                            <i class="fas fa-history" style="color: var(--azul-incatec); font-size: 1.2rem; margin-right: 10px;"></i>
                            <h4 style="margin: 0; color: var(--gris-oscuro); font-weight: 600;">Historial de Intentos (${examenes.length})</h4>
                        </div>
                `;
                
                examenes.forEach((examen, index) => {
                    const totalCompetencias = examen.competencias ? examen.competencias.length : 0;
                    const competenciasAprobadas = examen.competencias ? examen.competencias.filter(c => c.estado_final === 'APROBADO').length : 0;
                    const esAprobado = (competenciasAprobadas === totalCompetencias && totalCompetencias > 0);
                    const porcentaje = parseFloat(examen.porcentaje || 0);
                    
                    contenidoHtml += `
                        <div style="
                            border: 1px solid #e9ecef;
                            border-left: 4px solid ${esAprobado ? 'var(--verde-success)' : 'var(--rojo-danger)'};
                            border-radius: 10px;
                            margin-bottom: 15px;
                            background: white;
                            box-shadow: var(--sombra-suave);
                            overflow: hidden;
                        ">
                            <!-- Header del intento -->
                            <div style="
                                background: ${esAprobado ? '#f8fff8' : '#fff5f5'};
                                padding: 15px 20px;
                                border-bottom: 1px solid #e9ecef;
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                            ">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                                        <span style="
                                            background: ${esAprobado ? 'var(--verde-success)' : 'var(--rojo-danger)'};
                                            color: white;
                                            padding: 4px 8px;
                                            border-radius: 6px;
                                            font-size: 0.8rem;
                                            font-weight: 600;
                                        ">
                                            INTENTO #${examen.intento_numero}
                                        </span>
                                        <span style="
                                            background: ${esAprobado ? 'var(--verde-success)' : 'var(--rojo-danger)'};
                                            color: white;
                                            padding: 4px 12px;
                                            border-radius: 15px;
                                            font-size: 0.75rem;
                                            font-weight: 600;
                                        ">
                                            ${esAprobado ? '✅ APROBADO' : '❌ REPROBADO'}
                                        </span>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #6c757d;">
                                        <i class="fas fa-calendar" style="margin-right: 5px;"></i>
                                        ${examen.fecha_formato || 'N/A'}
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="
                                        font-size: 2rem;
                                        font-weight: 700;
                                        color: ${porcentaje >= 70 ? 'var(--verde-success)' : 'var(--rojo-danger)'};
                                        line-height: 1;
                                    ">
                                        ${porcentaje.toFixed(1)}%
                                    </div>
                                    <div style="font-size: 0.75rem; color: #6c757d; margin-top: 2px;">
                                        ${examen.respuestas_correctas || 0}/${examen.total_preguntas || 0} correctas
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Métricas rápidas -->
                            <div style="padding: 15px 20px; background: #fafafa;">
                                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; text-align: center;">
                                    <div>
                                        <div style="font-size: 1.2rem; font-weight: 600; color: var(--azul-incatec);">${examen.total_preguntas || 0}</div>
                                        <div style="font-size: 0.7rem; color: #6c757d;">Preguntas</div>
                                    </div>
                                    <div>
                                        <div style="font-size: 1.2rem; font-weight: 600; color: var(--verde-success);">${examen.respuestas_correctas || 0}</div>
                                        <div style="font-size: 0.7rem; color: #6c757d;">Correctas</div>
                                    </div>
                                    <div>
                                        <div style="font-size: 1.2rem; font-weight: 600; color: var(--naranja-warning);">${totalCompetencias}</div>
                                        <div style="font-size: 0.7rem; color: #6c757d;">Competencias</div>
                                    </div>
                                    <div>
                                        <div style="font-size: 1.2rem; font-weight: 600; color: var(--verde-success);">${competenciasAprobadas}</div>
                                        <div style="font-size: 0.7rem; color: #6c757d;">Aprobadas</div>
                                    </div>
                                </div>
                            </div>
                    `;
                    
                    // Competencias (más compactas)
                    if (examen.competencias && examen.competencias.length > 0) {
                        contenidoHtml += `
                            <div style="padding: 15px 20px; border-top: 1px solid #e9ecef;">
                                <h6 style="margin: 0 0 12px 0; color: var(--gris-oscuro); font-size: 0.9rem; font-weight: 600;">
                                    Detalle por Competencia:
                                </h6>
                                <div style="display: grid; gap: 8px;">
                        `;
                        
                        examen.competencias.forEach((comp) => {
                            const esAprobadoComp = comp.estado_final === 'APROBADO';
                            const esRehabilitado = comp.origen_aprobacion === 'REHABILITADO';
                            const porcentajeComp = parseFloat(comp.porcentaje || 0);
                            
                            contenidoHtml += `
                                <div style="
                                    background: ${esAprobadoComp ? '#f0f9f0' : '#fef7f7'};
                                    border: 1px solid ${esAprobadoComp ? '#c8e6c9' : '#ffcdd2'};
                                    border-radius: 6px;
                                    padding: 10px;
                                    display: flex;
                                    justify-content: space-between;
                                    align-items: center;
                                    ${esRehabilitado ? 'border-left: 3px solid var(--naranja-warning);' : ''}
                                ">
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; color: var(--gris-oscuro); font-size: 0.85rem; margin-bottom: 3px;">
                                            ${comp.competencia_nombre}
                                            ${esRehabilitado ? `
                                                <span style="
                                                    background: var(--naranja-warning);
                                                    color: white;
                                                    padding: 1px 6px;
                                                    border-radius: 8px;
                                                    font-size: 0.6rem;
                                                    margin-left: 5px;
                                                ">REHABILITADA</span>
                                            ` : ''}
                                        </div>
                                        <div style="font-size: 0.7rem; color: #6c757d;">
                                            ID: ${comp.competencia_id} • ${comp.respuestas_correctas}/${comp.total_preguntas} correctas
                                        </div>
                                    </div>
                                    <div style="text-align: right; margin-left: 15px;">
                                        <div style="
                                            font-size: 1rem;
                                            font-weight: 700;
                                            color: ${esAprobadoComp ? 'var(--verde-success)' : 'var(--rojo-danger)'};
                                            margin-bottom: 2px;
                                        ">
                                            ${porcentajeComp.toFixed(1)}%
                                        </div>
                                        <span style="
                                            background: ${esAprobadoComp ? 'var(--verde-success)' : 'var(--rojo-danger)'};
                                            color: white;
                                            padding: 2px 6px;
                                            border-radius: 8px;
                                            font-size: 0.6rem;
                                            font-weight: 600;
                                        ">
                                            ${comp.estado_final}
                                        </span>
                                    </div>
                                </div>
                            `;
                        });
                        
                        contenidoHtml += `
                                </div>
                            </div>
                        `;
                    }
                    
                    contenidoHtml += `</div>`;
                });
                
                contenidoHtml += `</div>`;
            }
            
            // Rehabilitaciones (rediseñadas)
            if (rehabilitaciones.length > 0) {
                contenidoHtml += `
                    <div style="background: #fff9e6; border-top: 3px solid var(--naranja-warning); padding: 20px;">
                        <div style="display: flex; align-items: center; margin-bottom: 15px;">
                            <i class="fas fa-redo" style="color: var(--naranja-warning); font-size: 1.2rem; margin-right: 10px;"></i>
                            <h4 style="margin: 0; color: var(--gris-oscuro); font-weight: 600;">
                                Rehabilitaciones Otorgadas (${rehabilitaciones.length})
                            </h4>
                        </div>
                        <div style="display: grid; gap: 10px;">
                `;
                
                rehabilitaciones.forEach(rehab => {
                    const esCompletada = rehab.completada;
                    
                    contenidoHtml += `
                        <div style="
                            background: white;
                            border: 1px solid #e0e0e0;
                            border-left: 4px solid ${esCompletada ? 'var(--verde-success)' : 'var(--naranja-warning)'};
                            border-radius: 8px;
                            padding: 12px 15px;
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                        ">
                            <div>
                                <div style="font-weight: 600; color: var(--gris-oscuro); margin-bottom: 3px;">
                                    Competencia: ${rehab.competencia_nombre || `ID ${rehab.competencia_id}`}
                                </div>
                                <div style="font-size: 0.8rem; color: #6c757d;">
                                    Otorgada: ${rehab.fecha_formato || 'N/A'}
                                    ${esCompletada ? ` • Utilizada: ${rehab.fecha_completada_formato || 'N/A'}` : ''}
                                </div>
                            </div>
                            <span style="
                                background: ${esCompletada ? 'var(--verde-success)' : 'var(--naranja-warning)'};
                                color: white;
                                padding: 4px 10px;
                                border-radius: 12px;
                                font-size: 0.75rem;
                                font-weight: 600;
                            ">
                                ${esCompletada ? '✅ UTILIZADA' : '⏳ PENDIENTE'}
                            </span>
                        </div>
                    `;
                });
                
                contenidoHtml += `
                        </div>
                    </div>
                `;
            }
            
            // Estado vacío
            if (examenes.length === 0 && rehabilitaciones.length === 0) {
                contenidoHtml = `
                    <div style="text-align: center; padding: 60px 20px; color: #6c757d;">
                        <i class="fas fa-clipboard-list" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.3; color: var(--azul-incatec);"></i>
                        <h5 style="color: var(--gris-oscuro); margin-bottom: 10px;">Sin historial disponible</h5>
                        <p style="margin: 0; font-size: 0.9rem;">Este participante aún no ha realizado ningún examen.</p>
                    </div>
                `;
            }
            
            document.getElementById('modalHistorialContenido').innerHTML = contenidoHtml;
        }
        
        function mostrarError(mensaje) {
            document.getElementById('modalHistorialContenido').innerHTML = `
                <div style="text-align: center; padding: 50px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 20px;"></i>
                    <h5>Error al cargar el historial</h5>
                    <p>${mensaje}</p>
                </div>
            `;
        }

        // Función para cerrar el modal
        function cerrarModalHistorial() {
            document.getElementById('modalHistorial').style.display = 'none';
        }

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modal = document.getElementById('modalHistorial');
            if (event.target === modal) {
                cerrarModalHistorial();
            }
        }

        // Cerrar modal con ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('modalHistorial');
                if (modal.style.display === 'block' || modal.style.display === 'flex') {
                    cerrarModalHistorial();
                }
            }
        });

        // Búsqueda con notificación
        document.querySelector('form').addEventListener('submit', function(e) {
            const criterio = document.querySelector('.search-input').value.trim();
            if (!criterio) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Campo Requerido',
                    text: 'Por favor ingrese un criterio de búsqueda',
                    confirmButtonColor: '#ff9800'
                });
                return;
            }
            
            Swal.fire({
                title: 'Buscando...',
                html: `Buscando participantes que coincidan con: <strong>${criterio}</strong>`,
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        });
    </script>
    <?php require_once '../includes/footer.php'; ?>
</body>
</html>