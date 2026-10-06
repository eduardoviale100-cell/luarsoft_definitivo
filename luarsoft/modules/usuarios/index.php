<?php
/**
 * modules/usuarios/index.php
 * Redirecciona al listado general de Gestión de Usuarios.
 */
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Location: ' . url('modules/usuarios/listado.php'));
exit;
