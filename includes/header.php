<?php
// Siempre incluir verificar_sesion (activa sesión y define tienePermiso())
require_once '../controllers/verificar_sesion.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examen de Ingreso - Panel Admin</title>

    <!-- Favicon INCATEC -->
    <link rel="icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">
    <link rel="apple-touch-icon" href="/examen_ingreso/assets/images/incatec-min-color.ico">

    <!-- Estilos -->
    <link rel="stylesheet" href="/examen_ingreso/assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
    <header>
        <h1><i class="bi bi-lightbulb"></i> Examen de Ingreso - Panel Admin</h1>

        <nav style="display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; position: relative;">

            <a href="/examen_ingreso/admin/index">
                <i class="bi bi-house-door-fill"></i> Dashboard
            </a>

            <?php if (tienePermiso('COMPETENCIAS')): ?>
            <a href="/examen_ingreso/admin/competencias">
                <i class="bi bi-journal-bookmark-fill"></i> Competencias
            </a>
            <?php endif; ?>

            <?php if (tienePermiso('PREGUNTAS')): ?>
            <a href="/examen_ingreso/admin/preguntas">
                <i class="bi bi-question-circle-fill"></i> Preguntas
            </a>
            <?php endif; ?>

            <?php if (tienePermiso('PARTICIPANTES')): ?>
            <a href="/examen_ingreso/admin/participantes">
                <i class="bi bi-people-fill"></i> Participantes
            </a>
            <?php endif; ?>

            <?php if (tienePermiso('BUSCAR RESULTADOS')): ?>
            <a href="/examen_ingreso/admin/buscar_resultados.php">
                <i class="bi bi-search"></i> Buscar Resultados
            </a>
            <?php endif; ?>

            <?php if (tienePermiso('INFORMES')): ?>
            <a href="/examen_ingreso/admin/informes" class="nav-link" title="Solo consulta - Rehabilitaciones automáticas">
                <i class="bi bi-filetype-pdf"></i> Informes
            </a>
            <?php endif; ?>

            <a href="/examen_ingreso/logout_admin.php"
               style="background-color: #dc3545; color: white; border-radius: 5px; padding: 10px; text-decoration: none;">
                <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
            </a>

            <!-- Nombre del usuario en la esquina superior derecha -->
            <div style="position: absolute; right: 15px; top: -40px; color: #f8f9fa; font-weight: bold;">
                👤 <?= htmlspecialchars($_SESSION['nombre_admin'] ?? 'Admin') ?>
            </div>
        </nav>
    </header>

    <main>
