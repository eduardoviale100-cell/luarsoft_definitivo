<?php
/**
 * config/conexion.php
 * ------------------------------------------------------------------
 * Configuración central del sistema LuarSoft (Eros Tecnología).
 *
 * ARQUITECTURA MULTI-TENANT:
 *   - Cada empresa/tenant tiene su propia BD: luarsoft_db_[nombre]
 *   - La BD central 'luarsoft' solo almacena metadatos globales,
 *     suscripciones y usuarios SuperAdmin de la plataforma.
 *   - REGLA DE ORO: Ninguna operación de un usuario tenant puede
 *     tocar la BD central ni la BD de otro tenant.
 *
 * ENCAMINAMIENTO DINÁMICO:
 *   - Si existe $_SESSION['tenant_db'], la conexión se dirige
 *     EXCLUSIVAMENTE a esa base de datos.
 *   - Si la BD del tenant no existe o falla, se destruye la sesión
 *     y se redirige al login. NUNCA se hace fallback a 'luarsoft'.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------
// RUTA BASE DEL SISTEMA (ajustar solo si se cambia de ubicación)
// ---------------------------------------------------------------
if (!defined('BASE_URL')) {
    define('BASE_URL', '/eros-proyecto/luarsoft/');
}

// ---------------------------------------------------------------
// DATOS DE CONEXIÓN A LA BASE DE DATOS (MULTI-TENANT DINÁMICO)
// ---------------------------------------------------------------
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

mysqli_report(MYSQLI_REPORT_OFF);

// Determinar la BD destino según sesión activa
$_dbTenantSesion = (!empty($_SESSION['tenant_db']) && is_string($_SESSION['tenant_db']))
    ? preg_replace('/[^a-zA-Z0-9_]/', '', $_SESSION['tenant_db'])
    : 'luarsoft';

if (!defined('DB_NAME')) {
    define('DB_NAME', $_dbTenantSesion);
}

$conexion = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// ---------------------------------------------------------------
// SEGURIDAD CRÍTICA: Si la BD del tenant falla, NO hacer fallback.
// Un usuario tenant que no pueda conectar a su BD debe ser
// deslogueado. Esto evita que por error opere contra la BD central.
// ---------------------------------------------------------------
if (!$conexion) {
    // Si estamos en una página pública (login, logout) no destruir sesión,
    // solo mostrar error de conexión a la BD central.
    $scriptName = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $paginasPublicas = ['login.php', 'logout.php', 'logout_force.php', 'sesion_cerrada.php'];

    if (!in_array($scriptName, $paginasPublicas, true) && !empty($_SESSION['usuario'])) {
        // Destruir sesión corrupta y redirigir al login con mensaje
        $_SESSION = [];
        session_destroy();
        // Limpiar cookie de sesión
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        header('Location: ' . rtrim(BASE_URL, '/') . '/login.php?err=db_tenant');
        exit;
    }

    // Para páginas públicas o primer acceso: intentar conectar a 'luarsoft'
    if (DB_NAME !== 'luarsoft') {
        $conexion = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, 'luarsoft');
    }

    if (!$conexion) {
        http_response_code(500);
        die('Error de conexión a la base de datos [' . htmlspecialchars(DB_NAME, ENT_QUOTES) . ']: ' . mysqli_connect_error());
    }
}

mysqli_set_charset($conexion, 'utf8mb4');

// Zona horaria por defecto (Perú / Lima)
date_default_timezone_set('America/Lima');
