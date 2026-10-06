<?php
/**
 * logout.php
 * ------------------------------------------------------------------
 * Cierra la sesión del usuario de forma limpia y completa:
 *   1. Vacía el array $_SESSION
 *   2. Elimina la cookie de sesión del navegador
 *   3. Destruye el archivo de sesión en el servidor
 *   4. Redirige al login
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vaciar todas las variables de sesión
$_SESSION = [];

// Eliminar la cookie de sesión del navegador
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destruir la sesión en el servidor
session_destroy();

header('Location: login.php');
exit;
