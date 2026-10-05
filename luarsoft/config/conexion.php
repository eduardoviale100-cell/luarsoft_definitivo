<?php
/**
 * config/conexion.php
 * ------------------------------------------------------------------
 * Configuración central del sistema LuarSoft (Eros Tecnología).
 * - Abre la sesión PHP.
 * - Define constantes de ruta base (BASE_URL) para que TODOS los
 *   enlaces, formularios y assets funcionen sin importar en qué
 *   carpeta del servidor se instale el sistema.
 * - Crea la conexión a la base de datos usando mysqli con manejo
 *   de errores y charset UTF-8 correcto.
 * ------------------------------------------------------------------
 * IMPORTANTE: Si el sistema se instala dentro de una subcarpeta del
 * servidor (por ejemplo http://localhost/luarsoft/), cambia el
 * valor de BASE_URL más abajo a esa ruta (ej: '/luarsoft/').
 * Si se instala en la raíz del dominio, deja BASE_URL en '/'.
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

// Si hay una base de datos de usuario (tenant) asignada en sesión, se conecta a ella.
// De lo contrario, conecta a la base de datos central 'luarsoft'.
$dbTenantSesion = (!empty($_SESSION['tenant_db']) && is_string($_SESSION['tenant_db']))
    ? preg_replace('/[^a-zA-Z0-9_]/', '', $_SESSION['tenant_db'])
    : 'luarsoft';

if (!defined('DB_NAME')) {
    define('DB_NAME', $dbTenantSesion);
}

mysqli_report(MYSQLI_REPORT_OFF);

$conexion = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Fallback de seguridad: si la BD del tenant falla, intentar conectar a 'luarsoft'
if (!$conexion && DB_NAME !== 'luarsoft') {
    $conexion = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, 'luarsoft');
    if ($conexion) {
        $_SESSION['tenant_db'] = 'luarsoft';
    }
}

if (!$conexion) {
    http_response_code(500);
    die("Error de conexión a la base de datos [" . htmlspecialchars(DB_NAME) . "]: " . mysqli_connect_error());
}

mysqli_set_charset($conexion, 'utf8mb4');

// Zona horaria por defecto (Perú)
date_default_timezone_set('America/Lima');
