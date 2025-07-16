<?php
session_start();
require_once 'config/db.php';

// Verificar si está logueado
if (!isset($_SESSION['participante_id'])) {
    header('Location: index.php');
    exit;
}

$participante_id = $_SESSION['participante_id'];
$participante_nombre = $_SESSION['participante_nombre'];
$alerta = null;

// Verificar mensaje de login exitoso
if (isset($_GET['mensaje']) && $_GET['mensaje'] === 'login_exitoso') {
    $alerta = ['tipo' => 'success', 'mensaje' => '¡Bienvenido! Has iniciado sesión correctamente.'];
}

// Verificar estado del participante y si puede realizar el examen
try {
    $stmt = $pdo->prepare("
        SELECT p.*, ae.nivel_dificultad,
               (SELECT COUNT(*) FROM respuestas WHERE id_participante = p.id_participante) as respuestas_previas,
               (SELECT COUNT(*) FROM historial_examenes WHERE participante_id = p.id_participante) as intentos_realizados
        FROM participantes p
        LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
        WHERE p.id_participante = ?
    ");
    $stmt->execute([$participante_id]);
    $participante_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$participante_info) {
        header('Location: index.php?error=participante_no_encontrado');
        exit;
    }

    // Verificar si ya aprobó el examen
    if ($participante_info['estado_examen'] === 'APROBADO') {
        header('Location: ver_resultado.php?mensaje=ya_aprobado');
        exit;
    }

    // Si viene con auto_rehab, verificar si tiene rehabilitaciones activas disponibles
    if (isset($_GET['auto_rehab'])) {
        // Verificar si tiene rehabilitaciones por competencia activas
        $stmt_rehab_check = $pdo->prepare("
            SELECT COUNT(*) as rehabilitaciones_activas 
            FROM rehabilitaciones_competencia 
            WHERE participante_id = ? AND estado = 'ACTIVA'
        ");
        $stmt_rehab_check->execute([$participante_id]);
        $tiene_rehabilitaciones_activas = $stmt_rehab_check->fetchColumn() > 0;

        if (!$tiene_rehabilitaciones_activas) {
            // No tiene rehabilitaciones activas, redirigir a resultados SIN redirección automática
            header('Location: ver_resultado.php?no_redirect=1&mensaje=sin_rehabilitaciones&from_exam=1');
            exit;
        }

        // Si tiene rehabilitaciones activas pero no está en estado REHABILITADO, actualizarlo
        if ($participante_info['estado_examen'] !== 'REHABILITADO') {
            $stmt_update_estado = $pdo->prepare("UPDATE participantes SET estado_examen = 'REHABILITADO' WHERE id_participante = ?");
            $stmt_update_estado->execute([$participante_id]);

            // Recargar información del participante
            $stmt_participante = $pdo->prepare("
                SELECT p.*, ae.nivel_dificultad,
                       (SELECT COUNT(*) FROM respuestas WHERE id_participante = p.id_participante) as respuestas_previas,
                       (SELECT COUNT(*) FROM historial_examenes WHERE participante_id = p.id_participante) as intentos_realizados
                FROM participantes p
                LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
                WHERE p.id_participante = ?
            ");
            $stmt_participante->execute([$participante_id]);
            $participante_info = $stmt_participante->fetch(PDO::FETCH_ASSOC);
        }
    }

    // Verificar si está reprobado y no rehabilitado
    if ($participante_info['estado_examen'] === 'REPROBADO') {
        header('Location: ver_resultado.php?mensaje=reprobado_sin_rehabilitar');
        exit;
    }

    // CORREGIDO: Verificar rehabilitaciones con PostgreSQL
    $stmt_rehab = $pdo->prepare("
        SELECT competencia_id, competencia_nombre 
        FROM rehabilitaciones_competencia 
        WHERE participante_id = ? AND estado = 'ACTIVA'
        ORDER BY competencia_nombre ASC
    ");
    $stmt_rehab->execute([$participante_id]);
    $competencias_rehabilitadas = $stmt_rehab->fetchAll(PDO::FETCH_ASSOC);

    // DEBUG: Log para rastrear el proceso
    error_log("DEBUG REHABILITACIÓN - Participante: $participante_id");
    error_log("DEBUG REHABILITACIÓN - Competencias encontradas: " . count($competencias_rehabilitadas));
    error_log("DEBUG REHABILITACIÓN - auto_rehab GET: " . (isset($_GET['auto_rehab']) ? $_GET['auto_rehab'] : 'no'));
    error_log("DEBUG REHABILITACIÓN - Estado actual: " . $participante_info['estado_examen']);

    // **CORREGIDO:** Solo procesar auto_rehab si realmente viene el parámetro Y hay rehabilitaciones
    if (isset($_GET['auto_rehab']) && !empty($competencias_rehabilitadas)) {
        error_log("DEBUG REHABILITACIÓN - Iniciando proceso de limpieza");

        // CORREGIDO: Limpiar datos SIEMPRE que venga de auto_rehab
        try {
            $pdo->beginTransaction();

            // Eliminar respuestas previas para permitir nuevo intento
            $stmt_del_resp = $pdo->prepare("DELETE FROM respuestas WHERE id_participante = ?");
            $result_resp = $stmt_del_resp->execute([$participante_id]);

            // Eliminar resultado actual para permitir recalculo
            $stmt_del_res = $pdo->prepare("DELETE FROM resultados WHERE participante_id = ?");
            $result_res = $stmt_del_res->execute([$participante_id]);

            // Eliminar detalles de resultado_competencias
            $stmt_del_comp = $pdo->prepare("
                DELETE FROM resultado_competencias 
                WHERE resultado_id IN (
                    SELECT id_resultado FROM resultados WHERE participante_id = ?
                )
            ");
            $result_comp = $stmt_del_comp->execute([$participante_id]);

            $pdo->commit();

            error_log("REHABILITACIÓN EXITOSA - Datos limpiados para participante $participante_id");

            // Actualizar información del participante después de limpiar
            $stmt->execute([$participante_id]);
            $participante_info = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("ERROR CRÍTICO limpiando datos para rehabilitación: " . $e->getMessage());
            $alerta = ['tipo' => 'error', 'mensaje' => 'Error preparando rehabilitación: ' . $e->getMessage()];
        }

        // Actualizar estado a REHABILITADO
        try {
            $stmt_update_estado = $pdo->prepare("UPDATE participantes SET estado_examen = 'REHABILITADO' WHERE id_participante = ?");
            $result_estado = $stmt_update_estado->execute([$participante_id]);

            if ($result_estado) {
                $participante_info['estado_examen'] = 'REHABILITADO';
                error_log("DEBUG REHABILITACIÓN - Estado actualizado a REHABILITADO");
            }
        } catch (Exception $e) {
            error_log("ERROR actualizando estado a REHABILITADO: " . $e->getMessage());
        }

        // Mensaje de rehabilitación automática
        $competencias_lista = implode(', ', array_column($competencias_rehabilitadas, 'competencia_nombre'));
        $alerta = [
            'tipo' => 'success',
            'mensaje' => "🎯 <strong>Rehabilitación Iniciada Exitosamente</strong><br>Vas a rehabilitar las competencias: <strong>$competencias_lista</strong>.<br>Solo verás preguntas de estas competencias. ¡Adelante!"
        ];

        error_log("DEBUG REHABILITACIÓN - Mensaje configurado, continuando con examen");
    } elseif (isset($_GET['auto_rehab']) && empty($competencias_rehabilitadas)) {
        // Si viene auto_rehab pero no hay rehabilitaciones, redirigir con error
        error_log("ERROR REHABILITACIÓN - Se intentó autorehab sin rehabilitaciones disponibles");
        header('Location: ver_resultado.php?no_redirect=1&mensaje=sin_rehabilitaciones&from_exam=1');
        exit;
    }

    // **CORREGIDO:** Solo mostrar alerta de rehabilitación disponible si hay competencias rehabilitadas
    if (
        $participante_info['respuestas_previas'] > 0 &&
        $participante_info['estado_examen'] === 'REHABILITADO' &&
        !isset($_GET['auto_rehab']) &&
        !isset($_GET['from_results']) &&
        !empty($competencias_rehabilitadas)
    ) {

        // Mostrar opción para iniciar rehabilitación
        $competencias_lista = implode(', ', array_column($competencias_rehabilitadas, 'competencia_nombre'));
        $alerta = [
            'tipo' => 'warning',
            'mensaje' => "🔄 <strong>Rehabilitación Disponible</strong><br>Tienes una rehabilitación pendiente para: <strong>$competencias_lista</strong>.<br><a href='examen.php?auto_rehab=1&from_result=1' style='color: #007bff; text-decoration: underline;'>Haz clic aquí para iniciar tu rehabilitación</a> o <a href='ver_resultado.php?from_rehab=1' style='color: #6c757d; text-decoration: underline;'>ver tus resultados anteriores</a>."
        ];
    }

    // Si tiene respuestas previas y NO está rehabilitado y NO viene de auto_rehab, redirigir a resultados
    if (
        $participante_info['respuestas_previas'] > 0 &&
        $participante_info['estado_examen'] !== 'REHABILITADO' &&
        !isset($_GET['auto_rehab']) &&
        !isset($_GET['from_results'])
    ) {
        error_log("DEBUG - Redirigiendo a resultados: respuestas_previas=" . $participante_info['respuestas_previas'] . ", estado=" . $participante_info['estado_examen']);
        header('Location: ver_resultado.php?from_exam=1');
        exit;
    }
} catch (Exception $e) {
    header('Location: index.php?error=error_verificacion');
    exit;
}

// Procesar respuestas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_respuestas'])) {
    try {
        error_log("INICIO PROCESAMIENTO - Participante: $participante_id");

        // Verificar que lleguen respuestas
        if (empty($_POST['respuestas'])) {
            throw new Exception('No se recibieron respuestas');
        }

        error_log("RESPUESTAS RECIBIDAS - Total: " . count($_POST['respuestas']));

        $pdo->beginTransaction();

        // Limpiar respuestas anteriores para evitar duplicados
        $stmt_clean = $pdo->prepare("DELETE FROM respuestas WHERE id_participante = ?");
        $stmt_clean->execute([$participante_id]);

        $respuestas_guardadas = 0;
        foreach ($_POST['respuestas'] as $id_pregunta => $id_opcion) {
            // Insertar nueva respuesta
            $stmt_insert = $pdo->prepare("INSERT INTO respuestas (id_participante, id_pregunta, id_opcion, fecha_respuesta) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            $result = $stmt_insert->execute([$participante_id, $id_pregunta, $id_opcion]);

            if ($result) {
                $respuestas_guardadas++;
                error_log("RESPUESTA GUARDADA - Pregunta: $id_pregunta, Opción: $id_opcion");
            } else {
                error_log("ERROR GUARDANDO - Pregunta: $id_pregunta, Opción: $id_opcion");
            }
        }

        $pdo->commit();

        error_log("RESPUESTAS GUARDADAS EXITOSAMENTE - Total: $respuestas_guardadas");

        // Verificar que se guardaron las respuestas
        $stmt_verify = $pdo->prepare("SELECT COUNT(*) FROM respuestas WHERE id_participante = ?");
        $stmt_verify->execute([$participante_id]);
        $respuestas_en_bd = $stmt_verify->fetchColumn();

        error_log("VERIFICACIÓN BD - Respuestas en BD: $respuestas_en_bd");

        if ($respuestas_en_bd == 0) {
            throw new Exception('Las respuestas no se guardaron en la base de datos');
        }

        // DEBUG: Verificar rehabilitaciones antes de calcular
        $stmt_debug_rehab = $pdo->prepare("
            SELECT * FROM rehabilitaciones_competencia 
            WHERE participante_id = ? AND estado = 'ACTIVA'
        ");
        $stmt_debug_rehab->execute([$participante_id]);
        $debug_rehab = $stmt_debug_rehab->fetchAll(PDO::FETCH_ASSOC);

        error_log("DEBUG ANTES CÁLCULO - Rehabilitaciones activas: " . count($debug_rehab));

        // CRÍTICO: Asegurar que calcular_resultado.php existe
        $archivo_calculo = __DIR__ . '/controllers/calcular_resultado.php';
        if (!file_exists($archivo_calculo)) {
            throw new Exception('No se encontró el archivo calcular_resultado.php');
        }

        // Calcular y guardar resultados
        require_once 'controllers/calcular_resultado.php';
        $resultado_calculo = calcularYGuardarResultado($participante_id, $pdo);

        error_log("RESULTADO CÁLCULO - Status: " . $resultado_calculo['status']);

        if ($resultado_calculo['status'] === 'success') {
            $data = $resultado_calculo['data'];

            error_log("CÁLCULO EXITOSO - Estado final: " . $data['estado']);

            // Verificar que se guardó el resultado en la BD
            $stmt_verify_result = $pdo->prepare("SELECT COUNT(*) FROM resultados WHERE participante_id = ?");
            $stmt_verify_result->execute([$participante_id]);
            $resultados_en_bd = $stmt_verify_result->fetchColumn();

            error_log("VERIFICACIÓN RESULTADO - Resultados en BD: $resultados_en_bd");

            if ($resultados_en_bd == 0) {
                throw new Exception('El resultado no se guardó en la base de datos');
            }

            // Verificar si era una rehabilitación y marcar como utilizadas
            if (isset($data['es_rehabilitacion']) && $data['es_rehabilitacion']) {
                error_log("REHABILITACIÓN DETECTADA - Marcando como utilizadas");

                // DEBUG: Verificar rehabilitaciones después de calcular
                $stmt_debug_rehab_after = $pdo->prepare("
                    SELECT * FROM rehabilitaciones_competencia 
                    WHERE participante_id = ?
                ");
                $stmt_debug_rehab_after->execute([$participante_id]);
                $debug_rehab_after = $stmt_debug_rehab_after->fetchAll(PDO::FETCH_ASSOC);

                error_log("DEBUG DESPUÉS CÁLCULO - Total rehabilitaciones: " . count($debug_rehab_after));
                foreach ($debug_rehab_after as $rehab) {
                    error_log("DEBUG REHAB AFTER - ID: {$rehab['id']}, Competencia: {$rehab['competencia_id']}, Estado: {$rehab['estado']}");
                }

                // Redirigir con mensaje específico de rehabilitación completada
                header('Location: ver_resultado.php?mensaje=rehabilitacion_completada&from_rehab=1&forced=1');
                exit;
            } else {
                // Redirigir normal
                header('Location: ver_resultado.php?mensaje=examen_completado&from_exam=1&forced=1');
                exit;
            }
        } else {
            error_log("ERROR EN CÁLCULO - Mensaje: " . $resultado_calculo['message']);
            $alerta = ['tipo' => 'error', 'mensaje' => 'Error al calcular el resultado: ' . $resultado_calculo['message']];
        }
    } catch (Exception $e) {
        // CORREGIDO: Solo hacer rollBack si hay una transacción activa
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("ERROR CRÍTICO al procesar respuestas: " . $e->getMessage());
        $alerta = ['tipo' => 'error', 'mensaje' => 'Error crítico: ' . $e->getMessage()];
    }
}

// Obtener información del participante y su examen asignado
try {
    $stmt = $pdo->prepare("
        SELECT p.*, ae.nivel_dificultad as nivel_examen, ae.fecha_asignacion
        FROM participantes p
        LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
        WHERE p.id_participante = ?
    ");
    $stmt->execute([$participante_id]);
    $participante = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$participante) {
        throw new Exception('Participante no encontrado');
    }

    // Verificar si tiene examen asignado
    if (!$participante['nivel_examen']) {
        $sin_examen = true;
        $preguntas = [];
    } else {
        $sin_examen = false;

        // Obtener preguntas según el nivel asignado y filtro de rehabilitación
        $preguntas = [];
        $params = [];

        $competencia_ids = !empty($competencias_rehabilitadas)
            ? array_column($competencias_rehabilitadas, 'competencia_id')
            : [];

        // Si NO es rehabilitación, obtener todas las competencias relacionadas con el nivel del examen
        if (empty($competencia_ids)) {
            $stmt_comp = $pdo->prepare("
        SELECT DISTINCT c.id_competencia
        FROM preguntas p
        JOIN competencias c ON p.id_competencia = c.id_competencia
        WHERE (p.nivel_dificultad = ? OR p.nivel_dificultad IS NULL)
    ");
            $stmt_comp->execute([$participante['nivel_examen']]);
            $competencia_ids = array_column($stmt_comp->fetchAll(PDO::FETCH_ASSOC), 'id_competencia');
        }

        // Obtener 5 preguntas aleatorias por cada competencia
        foreach ($competencia_ids as $id_competencia) {
            $stmt_preg = $pdo->prepare("
        SELECT p.id_pregunta, p.id_competencia, p.enunciado, p.nivel_dificultad, p.imagen_url, c.nombre as competencia
        FROM preguntas p 
        JOIN competencias c ON p.id_competencia = c.id_competencia
        WHERE (p.nivel_dificultad = ? OR p.nivel_dificultad IS NULL)
        AND p.id_competencia = ?
        ORDER BY RANDOM()
        LIMIT 4
    ");
            $stmt_preg->execute([$participante['nivel_examen'], $id_competencia]);
            $preguntas_competencia = $stmt_preg->fetchAll(PDO::FETCH_ASSOC);
            $preguntas = array_merge($preguntas, $preguntas_competencia);
        }

        // Quitar duplicados por id_pregunta (por seguridad)
        $preguntas_filtradas = [];
        $ids_procesados = [];

        foreach ($preguntas as $pregunta) {
            if (!in_array($pregunta['id_pregunta'], $ids_procesados)) {
                $preguntas_filtradas[] = $pregunta;
                $ids_procesados[] = $pregunta['id_pregunta'];
            }
        }

        $preguntas = $preguntas_filtradas;

        // Cargar opciones para cada pregunta (orden aleatorio)
        foreach ($preguntas as $key => $pregunta) {
            $stmt_opciones = $pdo->prepare("SELECT * FROM opciones WHERE id_pregunta = ? ORDER BY RANDOM()");
            $stmt_opciones->execute([$pregunta['id_pregunta']]);
            $preguntas[$key]['opciones'] = $stmt_opciones->fetchAll(PDO::FETCH_ASSOC);
        }

        // Limpieza
        unset($pregunta);


        $params = [$participante['nivel_examen']];

        // CORREGIDO: Si hay rehabilitaciones por competencias específicas, filtrar solo esas competencias
        if (!empty($competencias_rehabilitadas)) {
            $competencia_ids = array_column($competencias_rehabilitadas, 'competencia_id');
            $placeholders = str_repeat('?,', count($competencia_ids) - 1) . '?';
            //$sql_preguntas .= " AND p.id_competencia IN ($placeholders)";
            $params = array_merge($params, $competencia_ids);

            error_log("DEBUG REHABILITACIÓN - Filtrado por competencias IDs: " . implode(', ', $competencia_ids));
        }


        if (empty($preguntas) && !empty($competencias_rehabilitadas)) {
            error_log("ERROR CRÍTICO - No se encontraron preguntas para las competencias a rehabilitar");
            $alerta = ['tipo' => 'error', 'mensaje' => 'No se encontraron preguntas para las competencias que debes rehabilitar. Contacta al administrador.'];
        }

        // ️ FILTRO ADICIONAL: Eliminar duplicados por ID de pregunta en PHP
        $preguntas_filtradas = [];
        $ids_procesados = [];

        foreach ($preguntas as $pregunta) {
            if (!in_array($pregunta['id_pregunta'], $ids_procesados)) {
                $preguntas_filtradas[] = $pregunta;
                $ids_procesados[] = $pregunta['id_pregunta'];
            }
        }

        $preguntas = $preguntas_filtradas;

        // Log para depuración
        error_log("DEBUG - Total preguntas cargadas: " . count($preguntas));
        if (!empty($competencias_rehabilitadas)) {
            $nombres_competencias = array_column($competencias_rehabilitadas, 'competencia_nombre');
            error_log("DEBUG - Filtrado por competencias: " . implode(', ', $nombres_competencias));
        }

        // Obtener opciones para cada pregunta (ORDEN ALEATORIO)
        foreach ($preguntas as $key => $pregunta) {
            // CORREGIDO: RANDOM() es correcto para PostgreSQL
            $stmt_opciones = $pdo->prepare("SELECT * FROM opciones WHERE id_pregunta = ? ORDER BY RANDOM()");
            $stmt_opciones->execute([$pregunta['id_pregunta']]);
            $preguntas[$key]['opciones'] = $stmt_opciones->fetchAll(PDO::FETCH_ASSOC);
        }

        // 🛡️ IMPORTANTE: Limpiar la variable de referencia para evitar problemas
        unset($pregunta);

        // Verificar si ya completó el examen
        $stmt_respondidas = $pdo->prepare("SELECT COUNT(*) FROM respuestas WHERE id_participante = ?");
        $stmt_respondidas->execute([$participante_id]);
        $ya_respondio = $stmt_respondidas->fetchColumn() > 0;

        if ($ya_respondio) {
            header('Location: ver_resultado.php');
            exit;
        }
    }
} catch (Exception $e) {
    $alerta = ['tipo' => 'error', 'mensaje' => 'Error: ' . $e->getMessage()];
    $preguntas = [];
    $sin_examen = true;
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examen de Ingreso - INCATEC</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --azul-incatec: #003f91;
            --rojo-incatec: #d72638;
            --verde-success: #28a745;
            --blanco: #ffffff;
            --gris-suave: #f5f7fa;
            --gris-medio: #e0e6ed;
            --gris-oscuro: #2d3748;
            --sombra-suave: 0 2px 8px rgba(0, 0, 0, 0.1);
            --transicion: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif;
            background: var(--gris-suave);
            line-height: 1.6;
            color: var(--gris-oscuro);
        }

        .header {
            background: linear-gradient(90deg, var(--azul-incatec) 60%, #2356ad 100%);
            color: var(--blanco);
            padding: 20px 0;
            box-shadow: var(--sombra-suave);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.9rem;
        }

        .user-info .nivel-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
        }

        .btn-logout {
            background: var(--rojo-incatec);
            color: var(--blanco);
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transicion);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-logout:hover {
            background: #c21e2f;
            transform: translateY(-1px);
        }

        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .exam-info {
            background: var(--blanco);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: var(--sombra-suave);
            border-left: 4px solid var(--azul-incatec);
        }

        .exam-info h2 {
            color: var(--azul-incatec);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .info-item {
            background: var(--gris-suave);
            padding: 12px 16px;
            border-radius: 8px;
            border-left: 3px solid var(--azul-incatec);
        }

        .info-item label {
            font-weight: 600;
            color: var(--gris-oscuro);
            font-size: 0.85rem;
            display: block;
            margin-bottom: 4px;
        }

        .info-item span {
            color: var(--azul-incatec);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .info-item span i.fa-random {
            color: var(--verde-success);
            margin-right: 4px;
        }

        .no-exam-message {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            color: #856404;
        }

        .no-exam-message i {
            font-size: 2rem;
            margin-bottom: 12px;
            color: #ffc107;
        }

        /* Estilos para alerta de rehabilitación por competencia */
        .alert-rehabilitation {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 2px solid #2196f3;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(33, 150, 243, 0.2);
            animation: slideIn 0.5s ease-out;
        }

        .alert-content {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .alert-icon {
            background: #2196f3;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
            animation: pulse 2s infinite;
        }

        .alert-text h3 {
            color: #1565c0;
            margin-bottom: 12px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-text p {
            color: #0d47a1;
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .alert-text strong {
            background: #2196f3;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        .competencias-list {
            list-style: none;
            padding: 0;
            margin: 12px 0;
        }

        .competencias-list li {
            display: inline-block;
            background: rgba(33, 150, 243, 0.15);
            border: 1px solid #2196f3;
            margin: 4px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
        }

        .competencias-list li strong {
            background: none;
            color: #1976d2;
            padding: 0;
        }

        .rehabilitation-stats {
            margin-top: 16px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .rehabilitation-stats .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(33, 150, 243, 0.1);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            color: #0d47a1;
        }

        .rehabilitation-stats .stat-item i {
            color: #2196f3;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }
        }

        .exam-form {
            background: var(--blanco);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--sombra-suave);
        }

        .question-card {
            background: #fcfcfc;
            border: 1px solid var(--gris-medio);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            transition: var(--transicion);
        }

        .question-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .question-number {
            background: var(--azul-incatec);
            color: var(--blanco);
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .competencia-tag {
            background: var(--gris-medio);
            color: var(--gris-oscuro);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* CORREGIDO: Estilos para imágenes de preguntas */
        .question-image {
            margin: 16px 0;
            text-align: center;
        }

        .question-image-container {
            position: relative;
            display: inline-block;
            max-width: 100%;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid var(--gris-medio);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .question-image-container:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
        }

        .question-img-clickable {
            width: 100%;
            height: auto;
            max-height: 400px;
            max-width: 600px;
            object-fit: contain;
            transition: all 0.3s ease;
            display: block;
        }

        .question-img-clickable:hover {
            transform: scale(1.02);
        }

        .image-zoom-hint-question {
            position: absolute;
            top: 12px;
            right: 12px;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.8), rgba(0, 0, 0, 0.9));
            color: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .question-image-container:hover .image-zoom-hint-question {
            opacity: 1;
            transform: scale(1.1);
        }

        /* Efecto especial para preguntas con imagen */
        .question-card:has(.question-image-container) {
            border-left: 4px solid var(--azul-incatec);
            background: linear-gradient(135deg, #fff 0%, #f8fbff 100%);
        }

        .question-card:has(.question-image-container):hover {
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.15);
        }

        .question-card:has(.question-image-container)::before {
            content: '📷';
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.2rem;
            opacity: 0.6;
        }

        .question-text {
            font-size: 1.1rem;
            line-height: 1.6;
            color: var(--gris-oscuro);
            margin-bottom: 20px;
        }

        .options-container {
            display: grid;
            gap: 10px;
        }

        .option-item {
            position: relative;
            background: var(--blanco);
            border: 2px solid var(--gris-medio);
            border-radius: 8px;
            padding: 14px 16px;
            cursor: pointer;
            transition: var(--transicion);
        }

        .option-item:hover {
            border-color: var(--azul-incatec);
            background: #f0f8ff;
        }

        .option-item.selected {
            border-color: var(--azul-incatec);
            background: #e3f2fd;
        }

        .option-radio {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .option-label {
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            width: 100%;
        }

        .option-content {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            width: 100%;
        }

        .option-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .option-letter {
            background: var(--azul-incatec);
            color: var(--blanco);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .option-text {
            line-height: 1.4;
            margin-bottom: 8px;
        }

        .option-image-container {
            position: relative;
            display: inline-block;
            max-width: 200px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .option-image-container:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .option-image {
            width: 100%;
            height: auto;
            max-height: 150px;
            object-fit: cover;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .option-image:hover {
            transform: scale(1.02);
        }

        .image-zoom-hint {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            font-size: 12px;
        }

        .option-image-container:hover .image-zoom-hint {
            opacity: 1;
        }

        /* Estilos especiales para opciones con imágenes */
        .option-item:has(.option-image-container) {
            border-left: 4px solid var(--azul-incatec);
            background: linear-gradient(135deg, #fff 0%, #f8fbff 100%);
        }

        .option-item:has(.option-image-container):hover {
            background: linear-gradient(135deg, #f0f8ff 0%, #e3f2fd 100%);
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.1);
        }

        .option-item:has(.option-image-container).selected {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-color: var(--azul-incatec);
            box-shadow: 0 0 0 2px rgba(33, 150, 243, 0.2);
        }

        /* Responsive para imágenes de opciones */
        @media (max-width: 768px) {
            .option-image-container {
                max-width: 150px;
            }

            .option-image {
                max-height: 100px;
            }

            .option-content {
                flex-direction: column;
                gap: 8px;
            }

            .option-main {
                gap: 8px;
            }
        }

        .submit-section {
            margin-top: 40px;
            padding: 24px;
            background: var(--gris-suave);
            border-radius: 8px;
            text-align: center;
        }

        .btn-submit {
            background: var(--verde-success);
            color: var(--blanco);
            border: none;
            padding: 16px 32px;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transicion);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-submit:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
        }

        .btn-submit:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .progress-bar {
            background: var(--gris-medio);
            height: 6px;
            border-radius: 3px;
            margin: 20px 0;
            overflow: hidden;
        }

        .progress-fill {
            background: var(--verde-success);
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
            width: 0%;
        }

        .progress-text {
            text-align: center;
            color: var(--gris-oscuro);
            font-size: 0.9rem;
            margin-top: 8px;
        }

        /* ESTILOS PARA PAGINACIÓN */
        .pagination-container {
            background: var(--blanco);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--sombra-suave);
            border-top: 4px solid var(--azul-incatec);
        }

        .pagination-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 600;
            color: var(--azul-incatec);
        }

        .page-indicator {
            background: linear-gradient(135deg, var(--azul-incatec), #1565c0);
            color: var(--blanco);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .questions-summary {
            background: var(--gris-suave);
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            color: var(--gris-oscuro);
        }

        .page-content {
            min-height: 600px;
            display: none;
        }

        .page-content.active {
            display: block;
            animation: fadeInUp 0.4s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .pagination-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: var(--gris-suave);
            border-radius: 12px;
            margin-top: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn-nav {
            background: var(--azul-incatec);
            color: var(--blanco);
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transicion);
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 120px;
            justify-content: center;
        }

        .btn-nav:hover:not(:disabled) {
            background: #1565c0;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3);
        }

        .btn-nav:disabled {
            background: #9e9e9e;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-nav.btn-finish {
            background: var(--verde-success);
            font-weight: 700;
        }

        .btn-nav.btn-finish:hover:not(:disabled) {
            background: #1e7e34;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .page-dots {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .page-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #ccc;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .page-dot.active {
            background: var(--azul-incatec);
            transform: scale(1.3);
        }

        .page-dot.completed {
            background: var(--verde-success);
        }

        .page-dot:hover {
            transform: scale(1.2);
        }

        .page-progress {
            background: var(--gris-medio);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin: 15px 0;
        }

        .page-progress-fill {
            background: linear-gradient(90deg, var(--azul-incatec), var(--verde-success));
            height: 100%;
            border-radius: 4px;
            transition: width 0.4s ease;
            width: 0%;
        }

        .question-counter {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 2px solid var(--gris-medio);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
        }

        .question-counter h3 {
            color: var(--azul-incatec);
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .counter-stats {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .counter-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .counter-number {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--azul-incatec);
        }

        .counter-label {
            font-size: 0.8rem;
            color: var(--gris-oscuro);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .pagination-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .pagination-controls {
                flex-direction: column;
                gap: 10px;
            }

            .btn-nav {
                width: 100%;
                min-width: auto;
            }

            .page-dots {
                justify-content: center;
                order: -1;
            }

            .counter-stats {
                gap: 15px;
            }

            .page-content {
                min-height: 500px;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .container {
                padding: 0 15px;
                margin: 20px auto;
            }

            .exam-info,
            .exam-form {
                padding: 16px;
            }

            .question-card {
                padding: 16px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .question-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .btn-submit {
                padding: 14px 24px;
                font-size: 1rem;
            }

            /* 📱 RESPONSIVE para imágenes de preguntas */
            .question-image-container {
                max-width: 100%;
                border-radius: 8px;
            }

            .question-img-clickable {
                max-height: 250px;
            }

            .image-zoom-hint-question {
                width: 32px;
                height: 32px;
                font-size: 12px;
                top: 8px;
                right: 8px;
            }

            /* Reducir padding en tarjetas con imagen */
            .question-card:has(.question-image-container) {
                padding: 12px;
            }

            .question-card:has(.question-image-container)::before {
                font-size: 1rem;
                top: 10px;
                right: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="header-content">
            <h1>
                <i class="fas fa-graduation-cap"></i>
                Examen de Ingreso INCATEC
            </h1>
            <div class="user-info">
                <span><i class="fas fa-user"></i> <?= htmlspecialchars($participante_nombre) ?></span>
                <?php if (!$sin_examen): ?>
                    <span class="nivel-badge">
                        <i class="fas fa-layer-group"></i>
                        Nivel <?= ucfirst($participante['nivel_examen']) ?>
                    </span>
                <?php endif; ?>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    Salir
                </a>
            </div>
        </div>
    </div>

    <div class="container">

        <div class="exam-info">
            <h2><i class="fas fa-info-circle"></i> Información del Examen</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Participante:</label>
                    <span><?= htmlspecialchars($participante['nombre']) ?></span>
                </div>
                <div class="info-item">
                    <label>Usuario:</label>
                    <span><?= htmlspecialchars($participante['usuario']) ?></span>
                </div>
                <?php if (!$sin_examen): ?>
                    <div class="info-item">
                        <label>Nivel del Examen:</label>
                        <span><?= ucfirst($participante['nivel_examen']) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Total de Preguntas:</label>
                        <span><?= count($preguntas) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Fecha de Asignación:</label>
                        <span><?= date('d/m/Y', strtotime($participante['fecha_asignacion'])) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Orden de Opciones:</label>
                        <span><i class="fas fa-random"></i> Aleatorio</span>
                    </div>
                <?php else: ?>
                    <div class="info-item">
                        <label>Estado:</label>
                        <span>Sin examen asignado</span>
                    </div>
                <?php endif; ?>
            </div> <!-- Cierre correcto del info-grid -->
        </div>

        <?php if ($sin_examen): ?>
            <div class="no-exam-message">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Sin Examen Asignado</h3>
                <p>Aún no tienes un examen asignado. Por favor contacta al administrador para que te asigne un nivel de dificultad.</p>
            </div>
        <?php elseif (empty($preguntas)): ?>
            <div class="no-exam-message">
                <i class="fas fa-question-circle"></i>
                <h3>No hay preguntas disponibles</h3>
                <p>No se encontraron preguntas para tu nivel asignado. Contacta al administrador.</p>
            </div>
        <?php else: ?>
            <div class="exam-form">
                <?php
                // Calcular paginación
                $preguntas_por_pagina = 4;
                $total_paginas = ceil(count($preguntas) / $preguntas_por_pagina);
                ?>

                <!-- Contador de progreso general -->
                <div class="question-counter">
                    <h3><i class="fas fa-chart-line"></i> Progreso del Examen</h3>
                    <div class="counter-stats">
                        <div class="counter-item">
                            <span class="counter-number" id="answeredCount">0</span>
                            <span class="counter-label">Respondidas</span>
                        </div>
                        <div class="counter-item">
                            <span class="counter-number"><?= count($preguntas) ?></span>
                            <span class="counter-label">Total</span>
                        </div>
                        <div class="counter-item">
                            <span class="counter-number" id="remainingCount"><?= count($preguntas) ?></span>
                            <span class="counter-label">Pendientes</span>
                        </div>
                        <div class="counter-item">
                            <span class="counter-number"><?= $total_paginas ?></span>
                            <span class="counter-label">Páginas</span>
                        </div>
                    </div>
                    <div class="page-progress">
                        <div class="page-progress-fill" id="globalProgressFill"></div>
                    </div>
                </div>

                <form method="POST" id="examForm">
                    <input type="hidden" name="enviar_respuestas" value="1">

                    <?php for ($pagina = 1; $pagina <= $total_paginas; $pagina++): ?>
                        <?php
                        $inicio = ($pagina - 1) * $preguntas_por_pagina;
                        $fin = min($inicio + $preguntas_por_pagina, count($preguntas));
                        $preguntas_pagina = array_slice($preguntas, $inicio, $preguntas_por_pagina, true);
                        ?>

                        <div class="page-content <?= $pagina === 1 ? 'active' : '' ?>" id="page<?= $pagina ?>">
                            <div class="pagination-container">
                                <div class="pagination-header">
                                    <div class="page-info">
                                        <div class="page-indicator">
                                            <i class="fas fa-file-alt"></i>
                                            Página <?= $pagina ?> de <?= $total_paginas ?>
                                        </div>
                                        <div class="questions-summary">
                                            Preguntas <?= $inicio + 1 ?> - <?= $fin ?>
                                        </div>
                                    </div>
                                    <div class="page-dots">
                                        <?php for ($dot = 1; $dot <= $total_paginas; $dot++): ?>
                                            <div class="page-dot <?= $dot === $pagina ? 'active' : '' ?>"
                                                onclick="goToPage(<?= $dot ?>)"
                                                title="Ir a página <?= $dot ?>"></div>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <?php foreach ($preguntas_pagina as $index_global => $pregunta): ?>
                                    <?php $numero_pregunta = $inicio + array_search($index_global, array_keys($preguntas_pagina)) + 1; ?>
                                    <div class="question-card">
                                        <div class="question-header">
                                            <span class="question-number">Pregunta <?= $numero_pregunta ?></span>
                                            <span class="competencia-tag"><?= htmlspecialchars($pregunta['competencia']) ?></span>
                                        </div>
                                        <?php if (!empty($pregunta['imagen_url'])): ?>
                                            <div class="question-image">
                                                <div class="question-image-container" onclick="mostrarImagenPregunta('<?= addslashes($pregunta['imagen_url']) ?>', 'Pregunta <?= $numero_pregunta ?>')">
                                                    <img src="<?= htmlspecialchars($pregunta['imagen_url']) ?>"
                                                        alt="Imagen de la pregunta"
                                                        class="question-img-clickable">
                                                    <div class="image-zoom-hint-question">
                                                        <i class="fas fa-search-plus"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="question-text">
                                            <?= htmlspecialchars($pregunta['enunciado']) ?>
                                        </div>

                                        <div class="options-container">
                                            <?php
                                            $letters = ['A', 'B', 'C', 'D', 'E'];
                                            foreach ($pregunta['opciones'] as $opt_index => $opcion):
                                            ?>
                                                <div class="option-item" onclick="selectOption(this, <?= $pregunta['id_pregunta'] ?>, <?= $opcion['id_opcion'] ?>)">
                                                    <input type="radio"
                                                        name="respuestas[<?= $pregunta['id_pregunta'] ?>]"
                                                        value="<?= $opcion['id_opcion'] ?>"
                                                        id="opcion_<?= $opcion['id_opcion'] ?>"
                                                        class="option-radio"
                                                        required>
                                                    <label for="opcion_<?= $opcion['id_opcion'] ?>" class="option-label">
                                                        <div class="option-content">
                                                            <span class="option-letter"><?= $letters[$opt_index] ?></span>
                                                            <div class="option-main">
                                                                <span class="option-text"><?= htmlspecialchars($opcion['texto']) ?></span>
                                                                <?php if (!empty($opcion['imagen_url'])): ?>
                                                                    <div class="option-image-container">
                                                                        <img src="<?= htmlspecialchars($opcion['imagen_url']) ?>"
                                                                            alt="Imagen de la opción <?= $letters[$opt_index] ?>"
                                                                            class="option-image"
                                                                            onclick="event.stopPropagation(); mostrarImagenOpcion('<?= addslashes($opcion['imagen_url']) ?>', '<?= addslashes("Opción " . $letters[$opt_index]) ?>')">
                                                                        <div class="image-zoom-hint">
                                                                            <i class="fas fa-search-plus"></i>
                                                                        </div>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endfor; ?>

                    <!-- Controles de navegación únicos -->
                    <div class="pagination-controls">
                        <button type="button" class="btn-nav" id="prevBtn"
                            onclick="previousPage()" disabled>
                            <i class="fas fa-chevron-left"></i>
                            Anterior
                        </button>

                        <div class="page-dots" id="mainPageDots">
                            <?php for ($dot = 1; $dot <= $total_paginas; $dot++): ?>
                                <div class="page-dot <?= $dot === 1 ? 'active' : '' ?>"
                                    onclick="goToPage(<?= $dot ?>)"
                                    title="Página <?= $dot ?>"></div>
                            <?php endfor; ?>
                        </div>

                        <button type="button" class="btn-nav" id="nextBtn" onclick="nextPage()">
                            Siguiente
                            <i class="fas fa-chevron-right"></i>
                        </button>

                        <button type="submit" class="btn-nav btn-finish" id="finishBtn" style="display: none;" disabled>
                            <i class="fas fa-flag-checkered"></i>
                            Finalizar Examen
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($alerta): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: '<?= $alerta['tipo'] === 'success' ? 'success' : 'error' ?>',
                    title: '<?= $alerta['tipo'] === 'success' ? '¡Éxito!' : '¡Error!' ?>',
                    text: '<?= addslashes($alerta['mensaje']) ?>',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '<?= $alerta['tipo'] === 'success' ? '#28a745' : '#dc3545' ?>'
                });
            });
        </script>
    <?php endif; ?>

    <script>
        // Variables globales para paginación
        let totalQuestions = <?= count($preguntas) ?>;
        let totalPages = <?= isset($total_paginas) ? $total_paginas : 1 ?>;
        let currentPage = 1;
        let answeredQuestions = 0;
        let questionsPerPage = 4;

        // Función para seleccionar opción
        function selectOption(element, questionId, optionId) {
            // Remover selección anterior de esta pregunta
            const questionCard = element.closest('.question-card');
            const allOptions = questionCard.querySelectorAll('.option-item');
            allOptions.forEach(opt => opt.classList.remove('selected'));

            // Marcar opción seleccionada
            element.classList.add('selected');

            // Marcar radio button
            const radio = element.querySelector('input[type="radio"]');
            radio.checked = true;

            // Actualizar progreso
            updateProgress();

            // Animación de confirmación
            element.style.transform = 'scale(0.98)';
            setTimeout(() => {
                element.style.transform = 'scale(1)';
            }, 150);
        }

        // Actualizar progreso general
        function updateProgress() {
            const checkedRadios = document.querySelectorAll('input[type="radio"]:checked');
            answeredQuestions = checkedRadios.length;

            // Actualizar contadores
            document.getElementById('answeredCount').textContent = answeredQuestions;
            document.getElementById('remainingCount').textContent = totalQuestions - answeredQuestions;

            // Actualizar barra de progreso global
            const globalProgressPercent = (answeredQuestions / totalQuestions) * 100;
            document.getElementById('globalProgressFill').style.width = globalProgressPercent + '%';

            // Actualizar dots de páginas
            updatePageDots();

            // Actualizar botones de navegación
            updateNavigationButtons();
        }

        // Actualizar estado visual de los dots de páginas
        function updatePageDots() {
            // Actualizar dots principales
            const mainDots = document.querySelectorAll('#mainPageDots .page-dot');
            mainDots.forEach((dot, index) => {
                const pageNum = index + 1;
                dot.classList.remove('active', 'completed');

                if (pageNum === currentPage) {
                    dot.classList.add('active');
                }

                // Verificar si la página está completada
                if (isPageCompleted(pageNum)) {
                    dot.classList.add('completed');
                }
            });

            // Actualizar dots en headers de páginas
            const allPageDots = document.querySelectorAll('.page-dot');
            allPageDots.forEach((dot, index) => {
                // Solo actualizar si no es de los controles principales
                if (!dot.closest('#mainPageDots')) {
                    const pageNum = (index % totalPages) + 1;
                    dot.classList.remove('active', 'completed');

                    if (pageNum === currentPage) {
                        dot.classList.add('active');
                    }

                    if (isPageCompleted(pageNum)) {
                        dot.classList.add('completed');
                    }
                }
            });
        }

        // Actualizar estado de botones de navegación
        function updateNavigationButtons() {
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const finishBtn = document.getElementById('finishBtn');

            // Botón anterior
            if (prevBtn) {
                prevBtn.disabled = currentPage === 1;
            }

            // Botón siguiente y finalizar
            if (currentPage === totalPages) {
                // Última página: mostrar finalizar, ocultar siguiente
                if (nextBtn) nextBtn.style.display = 'none';
                if (finishBtn) {
                    finishBtn.style.display = 'flex';
                    finishBtn.disabled = answeredQuestions < totalQuestions;
                }
            } else {
                // Otras páginas: mostrar siguiente, ocultar finalizar
                if (nextBtn) nextBtn.style.display = 'flex';
                if (finishBtn) finishBtn.style.display = 'none';
            }
        }

        // Verificar si una página está completada
        function isPageCompleted(pageNum) {
            const startIndex = (pageNum - 1) * questionsPerPage;
            const endIndex = Math.min(startIndex + questionsPerPage, totalQuestions);

            for (let i = startIndex; i < endIndex; i++) {
                const questionCards = document.querySelectorAll('.question-card');
                if (questionCards[i]) {
                    const radio = questionCards[i].querySelector('input[type="radio"]:checked');
                    if (!radio) return false;
                }
            }
            return true;
        }

        // Navegar a página específica
        function goToPage(pageNum) {
            if (pageNum < 1 || pageNum > totalPages || pageNum === currentPage) return;

            // Ocultar página actual
            document.getElementById('page' + currentPage).classList.remove('active');

            // Mostrar nueva página
            currentPage = pageNum;
            document.getElementById('page' + currentPage).classList.add('active');

            // Actualizar dots
            updatePageDots();

            // Actualizar botones de navegación
            updateNavigationButtons();

            // Scroll suave al top
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Página anterior
        function previousPage() {
            if (currentPage > 1) {
                goToPage(currentPage - 1);
            }
        }

        // Página siguiente
        function nextPage() {
            if (currentPage < totalPages) {
                goToPage(currentPage + 1);
            }
        }

        // Atajos de teclado
        document.addEventListener('keydown', function(e) {
            // Solo si no está escribiendo en un input
            if (e.target.tagName.toLowerCase() === 'input') return;

            if (e.key === 'ArrowLeft' && currentPage > 1) {
                e.preventDefault();
                previousPage();
            } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
                e.preventDefault();
                nextPage();
            }
        });

        // Confirmación antes de enviar
        document.getElementById('examForm')?.addEventListener('submit', function(e) {
            if (answeredQuestions < totalQuestions) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Examen incompleto',
                    html: `
                    <div style="text-align: left; margin: 20px 0;">
                        <p style="margin-bottom: 15px;"><strong>Te faltan ${totalQuestions - answeredQuestions} preguntas por responder:</strong></p>
                        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107;">
                            <p style="margin: 0;"><i class="fas fa-exclamation-triangle" style="color: #856404;"></i> Por favor completa todas las preguntas antes de finalizar el examen.</p>
                        </div>
                    </div>
                `,
                    confirmButtonText: 'Revisar preguntas',
                    confirmButtonColor: '#ffc107',
                    width: '500px'
                });
                return false;
            }

            e.preventDefault();

            Swal.fire({
                title: '¿Finalizar examen?',
                html: `
                <div style="text-align: center; margin: 20px 0;">
                    <div style="background: #e8f5e9; padding: 20px; border-radius: 12px; margin-bottom: 15px;">
                        <i class="fas fa-check-circle" style="color: #28a745; font-size: 2rem; margin-bottom: 10px;"></i>
                        <h4 style="color: #28a745; margin: 0;">Examen completado</h4>
                        <p style="margin: 5px 0 0 0; color: #666;">Has respondido las ${totalQuestions} preguntas</p>
                    </div>
                    <p style="margin: 15px 0;"><strong>¿Estás seguro de que deseas finalizar?</strong></p>
                    <p style="color: #666; font-size: 0.9rem;">Una vez enviado no podrás hacer cambios.</p>
                </div>
            `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-paper-plane"></i> Sí, finalizar examen',
                cancelButtonText: '<i class="fas fa-edit"></i> Revisar respuestas',
                width: '550px',
                customClass: {
                    confirmButton: 'btn-confirm-large',
                    cancelButton: 'btn-cancel-large'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Enviando examen...',
                        html: `
                        <div style="text-align: center; padding: 20px;">
                            <i class="fas fa-paper-plane" style="font-size: 3rem; color: #28a745; margin-bottom: 15px;"></i>
                            <p>Procesando tus respuestas</p>
                        </div>
                    `,
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // Enviar formulario
                    setTimeout(() => {
                        e.target.submit();
                    }, 1000);
                }
            });
        });

        // Prevenir salida accidental
        window.addEventListener('beforeunload', function(e) {
            if (answeredQuestions > 0 && answeredQuestions < totalQuestions) {
                e.preventDefault();
                e.returnValue = '¿Estás seguro de que quieres salir? Perderás tu progreso en el examen.';
            }
        });

        // Inicialización al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            updateProgress();
            updateNavigationButtons();

            // Agregar estilos adicionales para los botones de confirmación
            const style = document.createElement('style');
            style.textContent = `
            .btn-confirm-large, .btn-cancel-large {
                padding: 12px 24px !important;
                font-size: 1rem !important;
                font-weight: 600 !important;
                border-radius: 8px !important;
                display: inline-flex !important;
                align-items: center !important;
                gap: 8px !important;
            }
            
            /* 🎨 Animaciones adicionales para imágenes clickeables */
            .question-image-container {
                position: relative;
            }
            
            .question-image-container::after {
                content: '🔍 Haz clic para ampliar';
                position: absolute;
                bottom: -30px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0,63,145,0.9);
                color: white;
                padding: 6px 12px;
                border-radius: 15px;
                font-size: 0.8rem;
                font-weight: 600;
                opacity: 0;
                transition: all 0.3s ease;
                pointer-events: none;
                white-space: nowrap;
                z-index: 10;
            }
            
            .question-image-container:hover::after {
                opacity: 1;
                bottom: -25px;
            }
            
            /* Animación de pulso sutil para destacar */
            @keyframes pulseHint {
                0%, 100% { box-shadow: 0 4px 20px rgba(0,63,145,0.15); }
                50% { box-shadow: 0 6px 25px rgba(0,63,145,0.25); }
            }
            
            .question-image-container {
                animation: pulseHint 3s ease-in-out infinite;
            }
            
            .question-image-container:hover {
                animation: none;
            }
        `;
            document.head.appendChild(style);

            // Mostrar mensaje de bienvenida al examen paginado
            if (totalQuestions > 0) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: 'Examen iniciado',
                    text: `${totalQuestions} preguntas organizadas en ${totalPages} páginas`,
                    showConfirmButton: false,
                    timer: 4000,
                    timerProgressBar: true
                });
            }
        });

        // 🔍 VARIABLES GLOBALES PARA ZOOM
        let currentZoomLevel = 1;
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let translateX = 0;
        let translateY = 0;
        let zoomableImage = null;

        // 🖼️ FUNCIÓN PARA MOSTRAR IMÁGENES DE OPCIONES EN MODAL CON ZOOM
        function mostrarImagenOpcion(src, titulo) {
            Swal.fire({
                title: titulo,
                html: `
                <div class="zoom-image-container">
                    <div class="zoom-controls">
                        <button type="button" class="zoom-btn zoom-in" onclick="adjustZoom(0.3)" title="Acercar (Ctrl + +)">
                            <i class="fas fa-search-plus"></i>
                        </button>
                        <button type="button" class="zoom-btn zoom-out" onclick="adjustZoom(-0.3)" title="Alejar (Ctrl + -)">
                            <i class="fas fa-search-minus"></i>
                        </button>
                        <button type="button" class="zoom-btn zoom-fit" onclick="fitToWindow()" title="Ajustar a ventana">
                            <i class="fas fa-compress-arrows-alt"></i>
                        </button>
                        <button type="button" class="zoom-btn zoom-reset" onclick="resetZoom()" title="Restablecer (Ctrl + 0)">
                            <i class="fas fa-expand-arrows-alt"></i>
                        </button>
                        <span class="zoom-level" id="zoomLevel">100%</span>
                    </div>
                    <div class="zoom-image-wrapper" id="zoomWrapper">
                        <img src="${src}" alt="${titulo}" class="zoomable-image" id="zoomableImage" draggable="false">
                    </div>
                    <div class="zoom-instructions">
                        <div class="instruction-row">
                            <i class="fas fa-mouse-pointer"></i>
                            <span>Rueda del mouse: Zoom</span>
                        </div>
                        <div class="instruction-row">
                            <i class="fas fa-hand-rock"></i>
                            <span>Arrastra: Mover imagen</span>
                        </div>
                        <div class="instruction-row">
                            <i class="fas fa-keyboard"></i>
                            <span>Ctrl + / Ctrl - : Zoom con teclado</span>
                        </div>
                    </div>
                </div>
            `,
                showCloseButton: true,
                showConfirmButton: false,
                width: '95%',
                customClass: {
                    popup: 'imagen-pregunta-modal-popup-zoom',
                    htmlContainer: 'zoom-html-container'
                },
                didOpen: () => {
                    initializeZoomModal();

                    // Agregar estilos específicos para preguntas
                    const style = document.createElement('style');
                    style.textContent = `
                    .imagen-pregunta-modal-popup-zoom {
                        border-radius: 20px !important;
                        box-shadow: 0 25px 80px rgba(0,63,145,0.2) !important;
                        background: linear-gradient(135deg, #fff 0%, #f0f8ff 100%) !important;
                        border: 3px solid #e3f2fd !important;
                    }
                    .imagen-pregunta-modal-popup-zoom .swal2-title {
                        color: #003f91 !important;
                        font-weight: 700 !important;
                        font-size: 1.3rem !important;
                        margin-bottom: 10px !important;
                    }
                    .imagen-pregunta-modal-popup-zoom .swal2-close {
                        background: #003f91 !important;
                        color: white !important;
                        border-radius: 50% !important;
                        font-size: 1.2rem !important;
                        padding: 8px !important;
                        width: 36px !important;
                        height: 36px !important;
                        top: 15px !important;
                        right: 15px !important;
                    }
                    .imagen-pregunta-modal-popup-zoom .swal2-close:hover {
                        background: #2356ad !important;
                        transform: scale(1.1) !important;
                    }
                `;
                    document.head.appendChild(style);
                },
                willClose: () => {
                    cleanupZoomModal();
                }
            });
        }

        // 🔍 FUNCIONES DE ZOOM PARA MODAL DE IMÁGENES

        // Inicializar zoom modal
        function initializeZoomModal() {
            currentZoomLevel = 1;
            translateX = 0;
            translateY = 0;
            isDragging = false;

            zoomableImage = document.getElementById('zoomableImage');
            const wrapper = document.getElementById('zoomWrapper');

            if (!zoomableImage || !wrapper) return;

            // Agregar estilos CSS dinámicos para el zoom
            addZoomStyles();

            // Event listeners para zoom con rueda del mouse
            wrapper.addEventListener('wheel', handleWheelZoom, {
                passive: false
            });

            // Event listeners para drag & drop
            zoomableImage.addEventListener('mousedown', startDrag);
            document.addEventListener('mousemove', performDrag);
            document.addEventListener('mouseup', endDrag);

            // Event listeners para touch (móvil)
            zoomableImage.addEventListener('touchstart', handleTouchStart, {
                passive: false
            });
            zoomableImage.addEventListener('touchmove', handleTouchMove, {
                passive: false
            });
            zoomableImage.addEventListener('touchend', handleTouchEnd);

            // Event listener para teclado
            document.addEventListener('keydown', handleKeyboardZoom);

            // Prevenir selección de imagen
            zoomableImage.addEventListener('selectstart', e => e.preventDefault());
            zoomableImage.addEventListener('dragstart', e => e.preventDefault());

            // Centrar imagen inicialmente
            centerImage();
            updateZoomLevel();
        }

        // Limpiar event listeners
        function cleanupZoomModal() {
            if (zoomableImage) {
                const wrapper = document.getElementById('zoomWrapper');
                if (wrapper) {
                    wrapper.removeEventListener('wheel', handleWheelZoom);
                }

                zoomableImage.removeEventListener('mousedown', startDrag);
                zoomableImage.removeEventListener('touchstart', handleTouchStart);
                zoomableImage.removeEventListener('touchmove', handleTouchMove);
                zoomableImage.removeEventListener('touchend', handleTouchEnd);
                zoomableImage.removeEventListener('selectstart', e => e.preventDefault());
                zoomableImage.removeEventListener('dragstart', e => e.preventDefault());
            }

            document.removeEventListener('mousemove', performDrag);
            document.removeEventListener('mouseup', endDrag);
            document.removeEventListener('keydown', handleKeyboardZoom);
        }

        // Agregar estilos CSS para zoom
        function addZoomStyles() {
            if (document.getElementById('zoom-modal-styles')) return; // Evitar duplicados

            const style = document.createElement('style');
            style.id = 'zoom-modal-styles';
            style.textContent = `
            .zoom-image-container {
                display: flex;
                flex-direction: column;
                height: 80vh;
                max-height: 600px;
            }
            
            .zoom-controls {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 8px;
                padding: 12px;
                background: linear-gradient(135deg, #f8f9fa, #e9ecef);
                border-radius: 12px;
                margin-bottom: 15px;
                border: 2px solid #dee2e6;
                flex-shrink: 0;
            }
            
            .zoom-btn {
                background: #007bff;
                color: white;
                border: none;
                border-radius: 8px;
                padding: 10px 12px;
                cursor: pointer;
                transition: all 0.2s ease;
                font-size: 14px;
                min-width: 44px;
                height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .zoom-btn:hover {
                background: #0056b3;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(0,123,255,0.3);
            }
            
            .zoom-btn:active {
                transform: translateY(0);
            }
            
            .zoom-btn.zoom-reset {
                background: #28a745;
            }
            
            .zoom-btn.zoom-reset:hover {
                background: #1e7e34;
            }
            
            .zoom-btn.zoom-fit {
                background: #6c757d;
            }
            
            .zoom-btn.zoom-fit:hover {
                background: #545b62;
            }
            
            .zoom-level {
                background: white;
                padding: 8px 12px;
                border-radius: 6px;
                font-weight: 600;
                color: #495057;
                border: 2px solid #dee2e6;
                min-width: 60px;
                text-align: center;
                font-size: 14px;
            }
            
            .zoom-image-wrapper {
                flex: 1;
                overflow: hidden;
                border-radius: 12px;
                border: 2px solid #dee2e6;
                background: #f8f9fa;
                position: relative;
                cursor: grab;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .zoom-image-wrapper.dragging {
                cursor: grabbing;
            }
            
            .zoomable-image {
                max-width: none;
                max-height: none;
                transition: transform 0.2s ease;
                user-select: none;
                -webkit-user-select: none;
                -moz-user-select: none;
                -ms-user-select: none;
            }
            
            .zoom-instructions {
                margin-top: 15px;
                padding: 12px;
                background: #e3f2fd;
                border-radius: 8px;
                border-left: 4px solid #2196f3;
                flex-shrink: 0;
            }
            
            .instruction-row {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 4px;
                font-size: 13px;
                color: #1976d2;
            }
            
            .instruction-row:last-child {
                margin-bottom: 0;
            }
            
            .instruction-row i {
                width: 16px;
                text-align: center;
            }
            
            .imagen-modal-popup-zoom {
                border-radius: 16px !important;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3) !important;
            }
            
            .zoom-html-container {
                padding: 0 !important;
                margin: 0 !important;
                max-height: none !important;
            }
            
            @media (max-width: 768px) {
                .zoom-image-container {
                    height: 70vh;
                }
                
                .zoom-controls {
                    gap: 4px;
                    padding: 8px;
                }
                
                .zoom-btn {
                    padding: 8px 10px;
                    min-width: 40px;
                    height: 40px;
                    font-size: 12px;
                }
                
                .zoom-level {
                    padding: 6px 8px;
                    font-size: 12px;
                    min-width: 50px;
                }
                
                .instruction-row {
                    font-size: 11px;
                }
            }
        `;
            document.head.appendChild(style);
        }

        // Resto de las funciones de zoom...
        function handleWheelZoom(e) {
            e.preventDefault();

            const rect = zoomableImage.getBoundingClientRect();
            const wrapper = document.getElementById('zoomWrapper');
            const wrapperRect = wrapper.getBoundingClientRect();

            const mouseX = e.clientX - wrapperRect.left;
            const mouseY = e.clientY - wrapperRect.top;

            const zoomDirection = e.deltaY > 0 ? -0.2 : 0.2;
            const oldZoom = currentZoomLevel;

            adjustZoom(zoomDirection);

            if (currentZoomLevel !== oldZoom) {
                const zoomRatio = currentZoomLevel / oldZoom;

                const centerX = wrapperRect.width / 2;
                const centerY = wrapperRect.height / 2;

                const deltaX = (mouseX - centerX) * (zoomRatio - 1);
                const deltaY = (mouseY - centerY) * (zoomRatio - 1);

                translateX -= deltaX;
                translateY -= deltaY;

                applyTransform();
            }
        }

        function adjustZoom(delta) {
            const newZoom = Math.max(0.1, Math.min(5, currentZoomLevel + delta));

            if (newZoom !== currentZoomLevel) {
                currentZoomLevel = newZoom;
                applyTransform();
                updateZoomLevel();
                constrainImage();
            }
        }

        function resetZoom() {
            currentZoomLevel = 1;
            translateX = 0;
            translateY = 0;
            applyTransform();
            updateZoomLevel();
            centerImage();
        }

        function fitToWindow() {
            const wrapper = document.getElementById('zoomWrapper');
            const wrapperRect = wrapper.getBoundingClientRect();

            const naturalWidth = zoomableImage.naturalWidth;
            const naturalHeight = zoomableImage.naturalHeight;

            const scaleX = (wrapperRect.width - 40) / naturalWidth;
            const scaleY = (wrapperRect.height - 40) / naturalHeight;

            currentZoomLevel = Math.min(scaleX, scaleY, 1);
            translateX = 0;
            translateY = 0;

            applyTransform();
            updateZoomLevel();
            centerImage();
        }

        function centerImage() {
            const wrapper = document.getElementById('zoomWrapper');
            const wrapperRect = wrapper.getBoundingClientRect();
            const imageRect = zoomableImage.getBoundingClientRect();

            const wrapperCenterX = wrapperRect.width / 2;
            const wrapperCenterY = wrapperRect.height / 2;

            const imageCenterX = imageRect.width / 2;
            const imageCenterY = imageRect.height / 2;

            translateX = wrapperCenterX - imageCenterX;
            translateY = wrapperCenterY - imageCenterY;

            applyTransform();
        }

        function constrainImage() {
            const wrapper = document.getElementById('zoomWrapper');
            const wrapperRect = wrapper.getBoundingClientRect();

            const imageWidth = zoomableImage.naturalWidth * currentZoomLevel;
            const imageHeight = zoomableImage.naturalHeight * currentZoomLevel;

            if (imageWidth > wrapperRect.width) {
                const maxTranslateX = (imageWidth - wrapperRect.width) / 2;
                translateX = Math.max(-maxTranslateX, Math.min(maxTranslateX, translateX));
            } else {
                translateX = 0;
            }

            if (imageHeight > wrapperRect.height) {
                const maxTranslateY = (imageHeight - wrapperRect.height) / 2;
                translateY = Math.max(-maxTranslateY, Math.min(maxTranslateY, translateY));
            } else {
                translateY = 0;
            }

            applyTransform();
        }

        function applyTransform() {
            if (zoomableImage) {
                zoomableImage.style.transform = `scale(${currentZoomLevel}) translate(${translateX / currentZoomLevel}px, ${translateY / currentZoomLevel}px)`;
            }
        }

        function updateZoomLevel() {
            const zoomLevelElement = document.getElementById('zoomLevel');
            if (zoomLevelElement) {
                zoomLevelElement.textContent = Math.round(currentZoomLevel * 100) + '%';
            }
        }

        function startDrag(e) {
            if (currentZoomLevel <= 1) return;

            e.preventDefault();
            isDragging = true;
            startX = e.clientX - translateX;
            startY = e.clientY - translateY;

            document.getElementById('zoomWrapper').classList.add('dragging');
        }

        function performDrag(e) {
            if (!isDragging) return;

            e.preventDefault();
            translateX = e.clientX - startX;
            translateY = e.clientY - startY;

            applyTransform();
        }

        function endDrag() {
            if (!isDragging) return;

            isDragging = false;
            constrainImage();

            const wrapper = document.getElementById('zoomWrapper');
            if (wrapper) {
                wrapper.classList.remove('dragging');
            }
        }

        // Manejo de touch para dispositivos móviles
        let lastTouchDistance = 0;
        let touchStartX = 0;
        let touchStartY = 0;
        let initialPinchZoom = 1;

        function handleTouchStart(e) {
            e.preventDefault();

            if (e.touches.length === 1) {
                // Toque simple para arrastrar
                if (currentZoomLevel > 1) {
                    isDragging = true;
                    touchStartX = e.touches[0].clientX - translateX;
                    touchStartY = e.touches[0].clientY - translateY;
                }
            } else if (e.touches.length === 2) {
                // Pellizcar para zoom
                isDragging = false;
                const distance = getTouchDistance(e.touches[0], e.touches[1]);
                lastTouchDistance = distance;
                initialPinchZoom = currentZoomLevel;
            }
        }

        function handleTouchMove(e) {
            e.preventDefault();

            if (e.touches.length === 1 && isDragging) {
                // Arrastrar
                translateX = e.touches[0].clientX - touchStartX;
                translateY = e.touches[0].clientY - touchStartY;
                applyTransform();
            } else if (e.touches.length === 2) {
                // Pellizcar para zoom
                const distance = getTouchDistance(e.touches[0], e.touches[1]);
                const scale = distance / lastTouchDistance;
                const newZoom = Math.max(0.1, Math.min(5, initialPinchZoom * scale));

                currentZoomLevel = newZoom;
                applyTransform();
                updateZoomLevel();
            }
        }

        function handleTouchEnd(e) {
            if (e.touches.length === 0) {
                isDragging = false;
                constrainImage();
            }
        }

        function getTouchDistance(touch1, touch2) {
            const dx = touch1.clientX - touch2.clientX;
            const dy = touch1.clientY - touch2.clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        // Manejo de teclado
        function handleKeyboardZoom(e) {
            if (!document.querySelector('.imagen-modal-popup-zoom')) return;

            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case '=':
                    case '+':
                        e.preventDefault();
                        adjustZoom(0.2);
                        break;
                    case '-':
                        e.preventDefault();
                        adjustZoom(-0.2);
                        break;
                    case '0':
                        e.preventDefault();
                        resetZoom();
                        break;
                }
            }
        }
    </script>

</body>

</html>