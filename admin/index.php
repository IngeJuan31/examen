<?php
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../controllers/verificar_sesion.php';
require_once '../controllers/recargar_permisos.php';



// Obtener estadísticas del sistema
try {
    // Competencias
    $total_competencias = $pdo->query("SELECT COUNT(*) FROM competencias")->fetchColumn();

    // Preguntas
    $total_preguntas = $pdo->query("SELECT COUNT(*) FROM preguntas")->fetchColumn();
    $preguntas_con_imagen = $pdo->query("SELECT COUNT(*) FROM preguntas WHERE imagen_url IS NOT NULL")->fetchColumn();

    // Opciones
    $total_opciones = $pdo->query("SELECT COUNT(*) FROM opciones")->fetchColumn();
    $opciones_con_imagen = $pdo->query("SELECT COUNT(*) FROM opciones WHERE imagen_url IS NOT NULL")->fetchColumn();

    // Participantes
    $total_participantes = $pdo->query("SELECT COUNT(*) FROM participantes")->fetchColumn();
    $participantes_con_examen = $pdo->query("SELECT COUNT(*) FROM asignaciones_examen")->fetchColumn();

    // Respuestas y Resultados
    $total_respuestas = $pdo->query("SELECT COUNT(*) FROM respuestas")->fetchColumn();
    $examenes_completados = $pdo->query("SELECT COUNT(*) FROM resultados")->fetchColumn();

    // Promedio general
    $promedio_general = $pdo->query("SELECT AVG(puntaje_total) FROM resultados")->fetchColumn() ?: 0;

    // Últimos resultados
    $stmt = $pdo->query("
        SELECT p.nombre, p.cedula, r.puntaje_total, r.fecha_examen
        FROM resultados r
        JOIN participantes p ON r.id_participante = p.id_participante
        ORDER BY r.fecha_examen DESC
        LIMIT 5
    ");
    $ultimos_resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Distribución por programa
    $stmt = $pdo->query("
        SELECT 
            p.programa, 
            COUNT(*) as cantidad,
            COALESCE(AVG(r.porcentaje), 0) as promedio_puntaje
        FROM 
            participantes p
        LEFT JOIN 
            resultados r ON p.id_participante = r.participante_id
        WHERE 
            p.programa IS NOT NULL AND p.programa != ''
        GROUP BY 
            p.programa
        ORDER BY 
            promedio_puntaje DESC
    ");
    $distribucion_programas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resultados por competencia
    $stmt = $pdo->query("
        SELECT c.nombre, AVG(rc.puntaje_competencia) as promedio
        FROM resultado_competencias rc
        JOIN competencias c ON rc.id_competencia = c.id_competencia
        GROUP BY c.id_competencia, c.nombre
        ORDER BY promedio DESC
    ");
    $competencias_rendimiento = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Si no hay datos, usamos datos de ejemplo para pruebas
if (empty($distribucion_programas)) {
    $distribucion_programas = [
        ['programa' => 'AUXILIAR EN SERVICIOS FARMACEUTICOS', 'cantidad' => 8, 'promedio_puntaje' => 94.53],
        ['programa' => 'AUXILIAR EN RECURSOS HUMANOS', 'cantidad' => 2, 'promedio_puntaje' => 93.75],
        ['programa' => 'AUXILIAR EN ENFERMERIA', 'cantidad' => 6, 'promedio_puntaje' => 90.63],
        ['programa' => 'AUXILIAR ADMINISTRATIVO', 'cantidad' => 2, 'promedio_puntaje' => 87.50],
        ['programa' => 'COSMETOLOGIA Y ESTETICA INTEGRAL', 'cantidad' => 10, 'promedio_puntaje' => 86.88],
        ['programa' => 'AUXILIAR EN SEGURIDAD LABORAL', 'cantidad' => 4, 'promedio_puntaje' => 84.38]
    ];
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo - INCATEC</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --azul-incatec: #2196F3;
            --verde-success: #4CAF50;
            --rojo-danger: #f44336;
            --naranja-warning: #ff9800;
            --purpura-info: #9c27b0;
            --gris-suave: #f8f9fa;
            --gris-oscuro: #333;
            --blanco: #ffffff;
            --sombra-suave: 0 2px 8px rgba(0, 0, 0, 0.1);
            --sombra-hover: 0 4px 15px rgba(0, 0, 0, 0.15);
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
            padding: 40px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: var(--sombra-suave);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="2"/><circle cx="30" cy="30" r="20" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="1"/><circle cx="70" cy="70" r="15" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="1"/></svg>') no-repeat center;
            opacity: 0.3;
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.2rem;
        }

        .nav-links {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--sombra-suave);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .nav-link {
            background: var(--gris-suave);
            color: var(--gris-oscuro);
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .nav-link:hover {
            background: var(--azul-incatec);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--sombra-hover);
        }

        .nav-link.active {
            background: var(--azul-incatec);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--sombra-suave);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--sombra-hover);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--azul-incatec);
        }

        .stat-card.success::before {
            background: var(--verde-success);
        }

        .stat-card.warning::before {
            background: var(--naranja-warning);
        }

        .stat-card.danger::before {
            background: var(--rojo-danger);
        }

        .stat-card.info::before {
            background: var(--purpura-info);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .stat-icon {
            background: var(--azul-incatec);
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-card.success .stat-icon {
            background: var(--verde-success);
        }

        .stat-card.warning .stat-icon {
            background: var(--naranja-warning);
        }

        .stat-card.danger .stat-icon {
            background: var(--rojo-danger);
        }

        .stat-card.info .stat-icon {
            background: var(--purpura-info);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--azul-incatec);
            line-height: 1;
        }

        .stat-card.success .stat-number {
            color: var(--verde-success);
        }

        .stat-card.warning .stat-number {
            color: var(--naranja-warning);
        }

        .stat-card.danger .stat-number {
            color: var(--rojo-danger);
        }

        .stat-card.info .stat-number {
            color: var(--purpura-info);
        }

        .stat-label {
            color: #666;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-sublabel {
            color: #999;
            font-size: 0.85rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .dashboard-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--sombra-suave);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .card-header h3 {
            color: var(--azul-incatec);
            font-size: 1.3rem;
            font-weight: 700;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }

        .recent-results {
            list-style: none;
        }

        .result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--azul-incatec);
        }

        .result-info h4 {
            color: var(--gris-oscuro);
            margin-bottom: 4px;
            font-size: 1rem;
        }

        .result-info p {
            color: #666;
            font-size: 0.85rem;
        }

        .result-score {
            background: var(--azul-incatec);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .quick-actions {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--sombra-suave);
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .action-btn {
            background: linear-gradient(135deg, var(--azul-incatec), #1976d2);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--sombra-hover);
        }

        .action-btn.success {
            background: linear-gradient(135deg, var(--verde-success), #388e3c);
        }

        .action-btn.warning {
            background: linear-gradient(135deg, var(--naranja-warning), #f57c00);
        }

        .action-btn.info {
            background: linear-gradient(135deg, var(--purpura-info), #7b1fa2);
        }

        .action-btn i {
            font-size: 2rem;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .header {
                padding: 25px;
            }

            .header h1 {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .nav-links {
                flex-direction: column;
            }

            .action-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>




    <!-- Estadísticas Principales -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Total Participantes</div>
                    <div class="stat-number"><?= number_format($total_participantes) ?></div>
                    <div class="stat-sublabel"><?= number_format($participantes_con_examen) ?> con examen asignado</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>

        <div class="stat-card success">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Exámenes Completados</div>
                    <div class="stat-number"><?= number_format($examenes_completados) ?></div>
                    <div class="stat-sublabel">Promedio: <?= number_format($promedio_general, 1) ?>%</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>

        <div class="stat-card warning">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Total Preguntas</div>
                    <div class="stat-number"><?= number_format($total_preguntas) ?></div>
                    <div class="stat-sublabel"><?= number_format($preguntas_con_imagen) ?> con imágenes</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-question-circle"></i>
                </div>
            </div>
        </div>

        <div class="stat-card info">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Total Competencias</div>
                    <div class="stat-number"><?= number_format($total_competencias) ?></div>
                    <div class="stat-sublabel">Áreas de evaluación</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-cogs"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Dashboard Principal -->
    <div class="dashboard-grid">
        <!-- Últimos Resultados -->
        <div class="dashboard-card">
            <div class="card-header">
                <i class="fas fa-clock"></i>
                <h3>Últimos Resultados</h3>
            </div>

            <?php if (!empty($ultimos_resultados)): ?>
                <ul class="recent-results">
                    <?php foreach ($ultimos_resultados as $resultado): ?>
                        <li class="result-item">
                            <div class="result-info">
                                <h4><?= htmlspecialchars($resultado['nombre']) ?></h4>
                                <p>
                                    <?= htmlspecialchars($resultado['cedula']) ?> -
                                    <?= date('d/m/Y H:i', strtotime($resultado['fecha_examen'])) ?>
                                </p>
                            </div>
                            <div class="result-score">
                                <?= number_format($resultado['puntaje_total'], 1) ?>%
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p style="text-align: center; color: #666; padding: 40px;">
                    <i class="fas fa-info-circle"></i><br>
                    No hay resultados disponibles aún
                </p>
            <?php endif; ?>
        </div>

        <!-- Promedio por Programa Académico -->
        <div class="dashboard-card">
            <div class="card-header">
                <i class="fas fa-graduation-cap"></i>
                <h3>Promedio por Programa Académico</h3>
            </div>

            <?php if (!empty($distribucion_programas)): ?>
                <div class="chart-container">
                    <canvas id="promedioProgramasChart"></canvas>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #666; padding: 40px;">
                    <i class="fas fa-info-circle"></i><br>
                    No hay datos de programas académicos o resultados aún
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Acciones Rápidas -->
    <div class="quick-actions">
        <div class="card-header">
            <i class="fas fa-bolt"></i>
            <h3>Acciones Rápidas</h3>
        </div>

        <div class="action-buttons">

            <?php if (tienePermiso('PARTICIPANTES')): ?>
                <a href="participantes.php" class="action-btn">
                    <i class="fas fa-user-plus"></i>
                    <span>Gestionar Participantes</span>
                </a>
            <?php endif; ?>

            <?php if (tienePermiso('PREGUNTAS')): ?>
                <a href="preguntas.php" class="action-btn success">
                    <i class="fas fa-plus-circle"></i>
                    <span>Crear Pregunta</span>
                </a>
            <?php endif; ?>

            <?php if (tienePermiso('BUSCAR RESULTADOS')): ?>
                <a href="buscar_resultados.php" class="action-btn warning">
                    <i class="fas fa-search"></i>
                    <span>Buscar Resultados</span>
                </a>
            <?php endif; ?>

            <?php if (tienePermiso('COMPETENCIAS')): ?>
                <a href="competencias.php" class="action-btn info">
                    <i class="fas fa-cogs"></i>
                    <span>Gestionar Competencias</span>
                </a>
            <?php endif; ?>

            <?php if (tienePermiso('COLABORADORES')): ?>
                <a href="crear.php" class="action-btn">
                    <i class="fas fa-cogs"></i>
                    <span>Gestionar Colaboradores</span>
                </a>
            <?php endif; ?>

            <?php if (tienePermiso('PERMISOS')): ?>
                <a href="../controllers/asignar_permisos.php" class="action-btn warning">
                    <i class="fas fa-cogs"></i>
                    <span>Gestionar Permisos</span>
                </a>
            <?php endif; ?>

        </div>

    </div>

    <?php if (!empty($distribucion_programas)): ?>
        <script>
            // Código limpio para el gráfico de distribución
            document.addEventListener('DOMContentLoaded', function() {
                // Verificamos que el elemento canvas exista
                const distribucionElement = document.getElementById('distribucionProgramasChart');
                const promedioElement = document.getElementById('promedioProgramasChart');
                
                if (distribucionElement) {
                    const ctxDistribucion = distribucionElement.getContext('2d');
                    new Chart(ctxDistribucion, {
                        type: 'doughnut',
                        data: {
                            labels: [
                                <?php foreach ($distribucion_programas as $programa): ?> 
                                    '<?= htmlspecialchars($programa['programa']) ?>',
                                <?php endforeach; ?>
                            ],
                            datasets: [{
                                data: [
                                    <?php foreach ($distribucion_programas as $programa): ?>
                                        <?= $programa['cantidad'] ?>,
                                    <?php endforeach; ?>
                                ],
                                backgroundColor: [
                                    '#2196F3', '#4CAF50', '#ff9800', '#f44336', '#9c27b0',
                                    '#009688', '#3F51B5', '#E91E63', '#FFC107', '#607D8B'
                                ],
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
                                            size: 12
                                        }
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return `${context.label}: ${context.raw} participantes`;
                                        }
                                    }
                                }
                            },
                            cutout: '60%'
                        }
                    });
                }
                
                if (promedioElement) {
                    const ctxPromedio = promedioElement.getContext('2d');
                    new Chart(ctxPromedio, {
                        type: 'bar',
                        data: {
                            labels: [
                                <?php foreach ($distribucion_programas as $prog): ?>
                                    '<?= htmlspecialchars($prog['programa']) ?>',
                                <?php endforeach; ?>
                            ],
                            datasets: [{
                                label: 'Promedio (%)',
                                data: [
                                    <?php foreach ($distribucion_programas as $prog): ?>
                                        <?= round($prog['promedio_puntaje'], 1) ?>,
                                    <?php endforeach; ?>
                                ],
                                backgroundColor: [
                                    '#4CAF50', '#2196F3', '#FF9800', '#F44336', '#9C27B0',
                                    '#009688', '#3F51B5', '#E91E63', '#FFC107', '#607D8B'
                                ],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            indexAxis: 'y',
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return `Promedio: ${context.raw}%`;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        display: false
                                    }
                                },
                                x: {
                                    beginAtZero: true,
                                    max: 100,
                                    ticks: {
                                        callback: function(value) {
                                            return value + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            });
        </script>
    <?php endif; ?>
    <?php require_once '../includes/footer.php'; ?>
</body>

</html>