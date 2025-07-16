<?php
// Configurar el código de respuesta HTTP 403
http_response_code(403);

// Configurar headers de seguridad
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado - Error 403</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }

        .container {
            background: white;
            padding: 3rem;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
            width: 90%;
            position: relative;
            overflow: hidden;
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #ff6b6b, #ffa726, #66bb6a, #42a5f5, #ab47bc);
        }

        .icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: #ff6b6b;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #2c3e50;
            font-weight: 700;
        }

        .error-code {
            font-size: 1.2rem;
            color: #7f8c8d;
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .message {
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 2rem;
            color: #555;
        }

        .suggestions {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            border-left: 4px solid #3498db;
        }

        .suggestions h3 {
            color: #2c3e50;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .suggestions ul {
            list-style: none;
            text-align: left;
        }

        .suggestions li {
            padding: 0.5rem 0;
            color: #666;
            position: relative;
            padding-left: 1.5rem;
        }

        .suggestions li::before {
            content: '→';
            position: absolute;
            left: 0;
            color: #3498db;
            font-weight: bold;
        }

        .buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: #666;
            border: 2px solid #e9ecef;
        }

        .btn-secondary:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }

        .footer {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
            color: #999;
            font-size: 0.9rem;
        }

        @media (max-width: 600px) {
            .container {
                padding: 2rem 1.5rem;
            }
            
            h1 {
                font-size: 2rem;
            }
            
            .buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚫</div>
        <h1>Acceso Denegado</h1>
        <div class="error-code">Error 403 - Forbidden</div>
        
        <div class="message">
            Lo sentimos, pero no tienes permisos para acceder a esta página. 
            Tu solicitud ha sido denegada por razones de seguridad.
        </div>

        <div class="suggestions">
            <h3>¿Qué puedes hacer?</h3>
            <ul>              
                <li>Contacta al administrador si crees que es un error</li>
                <li>Regresa a la página principal y navega desde allí</li>
                <li>Revisa que tengas los permisos necesarios</li>
            </ul>
        </div>

        <div class="buttons">
            <a href="../examen_ingreso/admin/index.php" class="btn btn-primary">🏠 Ir al Inicio</a>
            <a href="javascript:history.back()" class="btn btn-secondary">← Volver Atrás</a>
        </div>

        <div class="footer">
            <p>Si el problema persiste, contacta a soporte técnico</p>
            <p>Código de error: 403 | Fecha: <?php echo date('d/m/Y H:i:s'); ?></p>
        </div>
    </div>

    <script>
        // Log del intento de acceso (opcional)
        console.log('Acceso denegado registrado: ' + new Date().toLocaleString());
        
        // Opcional: Redirigir después de cierto tiempo
        // setTimeout(() => {
        //     window.location.href = '/';
        // }, 10000); // 10 segundos
    </script>
</body>
</html>