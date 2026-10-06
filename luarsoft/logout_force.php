<?php
/**
 * logout_force.php
 * Script de Limpieza Total (Reset de Sesión).
 * Destruye cualquier sesión corrupta o atascada y elimina las cookies.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_destroy();
header('Location: login.php');
exit;
