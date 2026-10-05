<?php
/**
 * modules/usuarios/eliminar.php
 * Compatibilidad con eliminación de usuarios hacia tenant_manager.
 */
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tenant_manager.php';

if (!esAdministrador()) {
    flash('error', 'Acceso denegado: solo el Administrador puede eliminar usuarios.');
    header('Location: ' . url('index.php'));
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$borrarBd = !empty($_GET['borrar_bd']);

if ($id <= 0) {
    flash('error', 'ID de usuario no válido.');
    header('Location: ' . url('modules/usuarios/listado.php'));
    exit;
}

$res = eliminarTenantCompleto($id, $borrarBd);
if ($res['success']) {
    flash('success', "Usuario '{$res['usuario']}' eliminado del sistema.");
} else {
    flash('error', $res['error']);
}

header('Location: ' . url('modules/usuarios/listado.php'));
exit;
