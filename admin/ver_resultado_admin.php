<?php
require_once '../config/db.php';

$participante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$participante = null;
$resultado = null;
$resultados_competencias = [];
$respuestas_detalladas = [];
$error = null;

if (!$participante_id) {
    header('Location: buscar_resultados.php');
    exit;
}

try {
    // Obtener información del participante
    $stmt = $pdo->prepare("
        SELECT p.*, ae.nivel_dificultad, ae.fecha_asignacion
        FROM participantes p
        LEFT JOIN asignaciones_examen ae ON p.id_participante = ae.id_participante
        WHERE p.id_participante = ?
    ");
    $stmt->execute([$participante_id]);
    $participante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$participante) {
        throw new Exception('Participante no encontrado');
    }
    
    // Obtener resultado general
    $stmt = $pdo->prepare("SELECT * FROM resultados WHERE participante_id = ?");
    $stmt->execute([$participante_id]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener resultados por competencia
    $stmt = $pdo->prepare("
        SELECT 
            rc.*,
            c.nombre as nombre_competencia
        FROM resultado_competencias rc
        JOIN competencias c ON rc.competencia_id = c.id_competencia
        JOIN resultados r ON rc.resultado_id = r.id_resultado
        WHERE r.participante_id = ?
        ORDER BY c.nombre
    ");
    $stmt->execute([$participante_id]);
    $resultados_competencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener respuestas detalladas
    $stmt = $pdo->prepare("
        SELECT 
            p.enunciado,
            p.imagen_url as pregunta_imagen,
            c.nombre as competencia,
            o.texto as respuesta_seleccionada,
            o.imagen_url as opcion_imagen,
            o.es_correcta,
            r.fecha_respuesta,
            (SELECT texto FROM opciones WHERE id_pregunta = p.id_pregunta AND es_correcta = true LIMIT 1) as respuesta_correcta
        FROM respuestas r
        JOIN preguntas p ON r.id_pregunta = p.id_pregunta
        JOIN opciones o ON r.id_opcion = o.id_opcion
        JOIN competencias c ON p.id_competencia = c.id_competencia
        WHERE r.id_participante = ?
        ORDER BY c.nombre, p.id_pregunta
    ");
    $stmt->execute([$participante_id]);
    $respuestas_detalladas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultado Detallado - INCATEC</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --azul-incatec: #2196F3;
            --verde-success: #4CAF50;
            --rojo-danger: #f44336;
            --naranja-warning: #ff9800;
            --gris-suave: #f8f9fa;
            --gris-oscuro: #333;
            --blanco: #ffffff;
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-info h1 {
            font-size: 2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.3);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .info-card h3 {
            color: var(--azul-incatec);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #666;
        }

        .info-value {
            color: var(--azul-incatec);
            font-weight: 600;
        }

        .results-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }

        .competencias-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .competencia-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--azul-incatec);
        }

        .competencia-score {
            font-size: 2rem;
            font-weight: bold;
            color: var(--azul-incatec);
            text-align: center;
            margin-bottom: 10px;
        }

        .progress-bar {
            background: #e0e0e0;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            background: var(--azul-incatec);
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .respuestas-detalladas {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .pregunta-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .pregunta-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pregunta-content {
            padding: 20px;
        }

        .pregunta-imagen {
            text-align: center;
            margin: 15px 0;
        }

        .pregunta-imagen img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .respuesta-correcta {
            background: #e8f5e9;
            border-left: 4px solid var(--verde-success);
        }

        .respuesta-incorrecta {
            background: #ffebee;
            border-left: 4px solid var(--rojo-danger);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-correcto {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-incorrecto {
            background: #ffebee;
            color: #c62828;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .competencias-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($error)): ?>
            <div style="background: #ffebee; color: #c62828; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle"></i>
                Error: <?= htmlspecialchars($error) ?>
            </div>
        <?php else: ?>
            <div class="header">
                <div class="header-info">
                    <h1>
                        <i class="fas fa-chart-line"></i>
                        Resultado Detallado
                    </h1>
                    <p><?= htmlspecialchars($participante['nombre']) ?> - <?= htmlspecialchars($participante['identificacion']) ?></p>
                </div>
                <a href="buscar_resultados.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i>
                    Volver
                </a>
            </div>

            <!-- Información del Participante -->
            <div class="info-grid">
                <div class="info-card">
                    <h3><i class="fas fa-user"></i> Información Personal</h3>
                    <div class="info-item">
                        <span class="info-label">Nombre:</span>
                        <span class="info-value"><?= htmlspecialchars($participante['nombre']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Cédula:</span>
                        <span class="info-value"><?= htmlspecialchars($participante['identificacion']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Usuario:</span>
                        <span class="info-value"><?= htmlspecialchars($participante['usuario']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fecha Registro:</span>
                        <span class="info-value"><?= date('d/m/Y', strtotime($participante['fecha_registro'])) ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <h3><i class="fas fa-clipboard-list"></i> Información del Examen</h3>
                    <div class="info-item">
                        <span class="info-label">Nivel Asignado:</span>
                        <span class="info-value">
                            <?= $participante['nivel_dificultad'] ? ucfirst($participante['nivel_dificultad']) : 'Sin asignar' ?>
                        </span>
                    </div>
                    <?php if ($resultado): ?>
                        <div class="info-item">
                            <span class="info-label">Fecha del Examen:</span>
                            <span class="info-value"><?= date('d/m/Y H:i', strtotime($resultado['fecha_realizacion'])) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Puntaje Total:</span>
                            <span class="info-value" style="font-size: 1.2rem;"><?= number_format($resultado['porcentaje'], 1) ?>%</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Respuestas Correctas:</span>
                            <span class="info-value"><?= $resultado['respuestas_correctas'] ?> / <?= $resultado['total_preguntas'] ?></span>
                        </div>
                    <?php else: ?>
                        <div class="info-item">
                            <span class="info-label">Estado:</span>
                            <span style="color: #ff9800;">Sin resultados disponibles</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($resultado): ?>
                <!-- Resultados Generales -->
                <div class="results-section">
                    <h3 style="color: var(--azul-incatec); margin-bottom: 20px;">
                        <i class="fas fa-chart-pie"></i>
                        Análisis de Resultados
                    </h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: center;">
                        <div class="chart-container">
                            <canvas id="resultChart"></canvas>
                        </div>
                        <div>
                            <h4 style="margin-bottom: 15px;">Resumen del Desempeño</h4>
                            <div style="font-size: 3rem; text-align: center; color: var(--azul-incatec); font-weight: bold; margin: 20px 0;">
                                <?= number_format($resultado['porcentaje'], 1) ?>%
                            </div>
                            <div style="text-align: center; color: #666;">
                                <?= $resultado['respuestas_correctas'] ?> de <?= $resultado['total_preguntas'] ?> respuestas correctas
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resultados por Competencia -->
                <?php if (!empty($resultados_competencias)): ?>
                    <div class="results-section">
                        <h3 style="color: var(--azul-incatec); margin-bottom: 20px;">
                            <i class="fas fa-cogs"></i>
                            Desempeño por Competencia
                        </h3>
                        
                        <div class="competencias-grid">
                            <?php foreach ($resultados_competencias as $comp): ?>
                                <div class="competencia-card">
                                    <h4 style="color: var(--azul-incatec); margin-bottom: 10px;">
                                        <?= htmlspecialchars($comp['nombre_competencia']) ?>
                                    </h4>
                                    <div class="competencia-score">
                                        <?= number_format($comp['porcentaje'], 1) ?>%
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= $comp['porcentaje'] ?>%"></div>
                                    </div>
                                    <div style="text-align: center; color: #666; font-size: 0.9rem;">
                                        <?= $comp['respuestas_correctas'] ?> / <?= $comp['total_preguntas'] ?> correctas
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Respuestas Detalladas -->
                <?php if (!empty($respuestas_detalladas)): ?>
                    <div class="respuestas-detalladas">
                        <h3 style="color: var(--azul-incatec); margin-bottom: 20px;">
                            <i class="fas fa-list-alt"></i>
                            Respuestas Detalladas
                        </h3>
                        
                        <?php foreach ($respuestas_detalladas as $index => $respuesta): ?>
                            <div class="pregunta-item">
                                <div class="pregunta-header">
                                    <div>
                                        <strong>Pregunta <?= $index + 1 ?></strong>
                                        <span style="color: #666; margin-left: 10px;"><?= htmlspecialchars($respuesta['competencia']) ?></span>
                                    </div>
                                    <span class="status-badge <?= $respuesta['es_correcta'] ? 'badge-correcto' : 'badge-incorrecto' ?>">
                                        <i class="fas fa-<?= $respuesta['es_correcta'] ? 'check' : 'times' ?>"></i>
                                        <?= $respuesta['es_correcta'] ? 'Correcta' : 'Incorrecta' ?>
                                    </span>
                                </div>
                                <div class="pregunta-content">
                                    <p style="margin-bottom: 15px; font-weight: 600;">
                                        <?= htmlspecialchars($respuesta['enunciado']) ?>
                                    </p>
                                    
                                    <?php if ($respuesta['pregunta_imagen']): ?>
                                        <div class="pregunta-imagen">
                                            <img src="../<?= htmlspecialchars($respuesta['pregunta_imagen']) ?>" alt="Imagen de la pregunta">
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div style="display: grid; gap: 10px;">
                                        <div class="<?= $respuesta['es_correcta'] ? 'respuesta-correcta' : 'respuesta-incorrecta' ?>" style="padding: 15px; border-radius: 8px;">
                                            <strong>Respuesta seleccionada:</strong>
                                            <div style="margin-top: 8px;">
                                                <?= htmlspecialchars($respuesta['respuesta_seleccionada']) ?>
                                                <?php if ($respuesta['opcion_imagen']): ?>
                                                    <div style="margin-top: 10px;">
                                                        <img src="../<?= htmlspecialchars($respuesta['opcion_imagen']) ?>" 
                                                             alt="Imagen de la opción seleccionada" 
                                                             style="max-width: 200px; max-height: 100px; border-radius: 6px;">
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if (!$respuesta['es_correcta']): ?>
                                            <div style="background: #e8f5e9; padding: 15px; border-radius: 8px; border-left: 4px solid var(--verde-success);">
                                                <strong>Respuesta correcta:</strong>
                                                <div style="margin-top: 8px;">
                                                    <?= htmlspecialchars($respuesta['respuesta_correcta']) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="margin-top: 15px; color: #666; font-size: 0.9rem;">
                                        <i class="fas fa-clock"></i>
                                        Respondida el <?= date('d/m/Y H:i', strtotime($respuesta['fecha_respuesta'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="background: #fff3e0; color: #ef6c00; padding: 30px; border-radius: 12px; text-align: center;">
                    <i class="fas fa-exclamation-circle" style="font-size: 3rem; margin-bottom: 15px;"></i>
                    <h3>Sin Resultados Disponibles</h3>
                    <p>Este participante aún no ha completado el examen.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($resultado): ?>
    <script>
        // Gráfico de resultados
        const ctx = document.getElementById('resultChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Correctas', 'Incorrectas'],
                datasets: [{
                    data: [<?= $resultado['respuestas_correctas'] ?>, <?= $resultado['total_preguntas'] - $resultado['respuestas_correctas'] ?>],
                    backgroundColor: ['#4CAF50', '#f44336'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            font: {
                                size: 14
                            }
                        }
                    }
                },
                cutout: '50%'
            }
        });
    </script>
    <?php endif; ?>
     <?php require_once '../includes/footer.php'; ?>
</body>
</html>
