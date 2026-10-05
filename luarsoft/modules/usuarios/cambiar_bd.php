<?php
/**
 * modules/usuarios/cambiar_bd.php
 * ------------------------------------------------------------------
 * Endpoint para que el Administrador cambie de base de datos activa
 * (Modo Soporte / Auditoría de datos de un usuario).
 */
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tenant_manager.php';

if (!esAdministrador()) {
    flash('error', 'Solo el Administrador puede cambiar de base de datos.');
    header('Location: ' . url('index.php'));
    exit;
}

$bdSolicitada = trim($_GET['bd'] ?? '');

if ($bdSolicitada !== '') {
    if (cambiarTenantActivo($bdSolicitada)) {
        flash('success', "Conectado exitosamente a la base de datos: {$bdSolicitada}");
    } else {
        flash('error', "No se pudo cambiar a la base de datos solicitada.");
    }
}

// Redirigir al panel principal o a la página anterior
$referer = $_SERVER['HTTP_REFERER'] ?? url('index.php');
header('Location: ' . $referer);
exit;
