# CodeViz Research Context

> **Note**: This file contains research context from CodeViz. Most recent contexts are at the bottom.

---

## Research Query

quiero que me verifiques el flujo del programa

*Session: 20cc50821760180fda20ab7fe61b2148 | Generated: 3/7/2025, 17:37:43*

### Analysis Summary

# Program Flow Analysis

This report outlines the general program flow of the application, detailing its high-level architecture and key functional flows.

## High-Level Architecture

The application follows a typical client-server architecture, primarily using PHP for server-side logic and HTML/CSS/JavaScript for the client-side interface. It interacts with a MySQL database for data persistence.

*   **Client-Side (Frontend):** Handles user interaction, displays information, and sends requests to the server. Key files include [index.php](index.php), [examen.php](examen.php), [ver_resultado.php](ver_resultado.php), and various HTML forms. Styling is managed by [assets/style.css](assets/style.css).
*   **Server-Side (Backend):** Processes client requests, interacts with the database, and generates dynamic content. This includes core PHP files like [login_prueba.php](login_prueba.php), [controllers/](controllers/) scripts, and [admin/](admin/) functionalities.
*   **Database:** Stores application data, including user information, exam questions, options, and results. Database connection is configured in [config/db.php](config/db.php).

The general data flow involves:
1.  User interacts with the frontend.
2.  Frontend sends requests (form submissions, AJAX) to backend PHP scripts.
3.  Backend scripts process requests, often interacting with the database.
4.  Backend sends responses (HTML, JSON) back to the frontend.
5.  Frontend updates the UI based on the response.

## Mid-Level Details

### User Authentication Flow

The application supports user login and registration.

*   **Login:**
    *   Users typically access the application through [index.php](index.php), which might redirect to a login page or present login options.
    *   The main login logic appears to be handled by [login_prueba.php](login_prueba.php) for general users and [login_test_administrativo.php](login_test_administrativo.php) for administrative users.
    *   These scripts likely validate user credentials against the database (via [config/db.php](config/db.php)) and manage session state.
    *   Upon successful login, users are redirected to their respective dashboards or the exam page.
*   **Registration:**
    *   New participants are registered via the [controllers/registrar_participante.php](controllers/registrar_participante.php) script.
    *   This script receives participant data (likely from a form) and inserts it into the database.

### Exam Taking Flow

This is a core functionality involving loading questions, saving answers, and calculating results.

*   **Starting the Exam:**
    *   After login, a user might be directed to [examen.php](examen.php).
    *   [examen.php](examen.php) is responsible for displaying the exam interface.
*   **Loading Questions:**
    *   The questions for the exam are dynamically loaded by [controllers/cargar_preguntas.php](controllers/cargar_preguntas.php). This script fetches questions and their associated options from the database.
    *   The questions can include images, which are stored in [uploads/preguntas/](uploads/preguntas/). Options can also have images in [uploads/opciones/](uploads/opciones/).
*   **Saving Answers:**
    *   As the user answers questions, their responses are sent to the server.
    *   The [controllers/guardar_respuestas.php](controllers/guardar_respuestas.php) script is responsible for receiving and storing these answers, likely associating them with the user and the specific exam attempt in the database.
*   **Calculating Results:**
    *   Once the exam is completed (or submitted), the [controllers/calcular_resultado.php](controllers/calcular_resultado.php) script processes the saved answers.
    *   This script compares the user's answers against the correct answers stored in the database and calculates a score or result. The result is then stored in the database.

### Result Viewing Flow

Users and administrators can view exam results.

*   **User View:**
    *   After completing an exam, or from a history page, users can view their results via [ver_resultado.php](ver_resultado.php).
    *   This script retrieves the calculated results for the logged-in user from the database and displays them.
    *   The [verificar_historial_examenes.php](verificar_historial_examenes.php) and [verificar_historial.php](verificar_historial.php) files suggest a mechanism for users to review their past exam attempts.
*   **Admin View:**
    *   Administrators can view detailed results for all participants.
    *   The [admin/buscar_resultados.php](admin/buscar_resultados.php) and [admin/ver_resultado_admin.php](admin/ver_resultado_admin.php) scripts facilitate searching and displaying participant results from the administrative panel.

### Administrative Flows

The `admin/` directory contains scripts for managing various aspects of the exam system.

*   **Dashboard:**
    *   The administrative entry point is likely [admin/dashboard.php](admin/dashboard.php).
    *   This provides an overview and navigation to other administrative functions.
*   **Managing Questions:**
    *   [admin/preguntas.php](admin/preguntas.php) is used for managing exam questions (adding, editing, deleting).
*   **Managing Options:**
    *   [admin/opciones.php](admin/opciones.php) and [admin/get_opciones.php](admin/get_opciones.php) are likely used for managing the answer options associated with questions.
*   **Managing Competencies:**
    *   [admin/competencias.php](admin/competencias.php) handles the management of competencies, which might be categories or subjects for questions.
*   **Managing Participants:**
    *   [admin/participantes.php](admin/participantes.php) allows administrators to view and manage registered participants.
*   **Rehabilitations:**
    *   Files like [admin/rehabilitaciones.php](admin/rehabilitaciones.php), [admin/rehabilitaciones_nuevo.php](admin/rehabilitaciones_nuevo.php), and [admin/rehabilitaciones_backup.php](admin/rehabilitaciones_backup.php) suggest a system for managing exam retakes or "rehabilitations" for participants. The [REHABILITACIONES_REESTRUCTURADO.md](REHABILITACIONES_REESTRUCTURADO.md) and [SISTEMA_REHABILITACIONES_COMPLETADO.md](SISTEMA_REHABILITACIONES_COMPLETADO.md) files provide documentation on this feature.
*   **Data Management:**
    *   [admin/insertar_datos_prueba.php](admin/insertar_datos_prueba.php) and [admin/limpiar_datos_prueba.php](admin/limpiar_datos_prueba.php) are likely used for populating and clearing test data in the database.
    *   [limpiar_base_datos.php](limpiar_base_datos.php) also suggests a general database cleanup utility.

### Utility and Setup Flows

*   **Database Setup/Update:**
    *   [setup_database.php](setup_database.php) and [database_update.sql](database_update.sql) are used for initial database setup and schema updates.
    *   [update_db.php](update_db.php) also points to database update functionality.
*   **Connection Testing:**
    *   [test_connection.php](test_connection.php) is likely a utility to verify the database connection.
*   **Debugging:**
    *   [debug_bucle.php](debug_bucle.php) suggests a script used for debugging purposes.
*   **Cookies:**
    *   [limpiar_cookies.php](limpiar_cookies.php) is used to clear browser cookies, potentially for testing or troubleshooting.
*   **AJAX Testing:**
    *   [probar_ajax_directo.php](probar_ajax_directo.php) indicates a script for direct testing of AJAX functionalities.

