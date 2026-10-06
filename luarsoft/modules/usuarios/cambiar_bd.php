<?php
/**
 * modules/usuarios/cambiar_bd.php
 * ------------------------------------------------------------------
 * Conmutación dinámica de base de datos activa para soporte y auditoría.
 * Exclusivo para el Administrador General (SuperAdmin).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permisos.php';
require_once __DIR__ . '/../../includes/tenant_manager.php';

// 1. Validación de Permisos Infranqueable: ÚNICAMENTE SuperAdmin
if (empty($_SESSION['es_superadmin']) && ($_SESSION['rol'] ?? '') !== 'superadmin') {
    http_response_code(403);
    flash('error', 'Acceso denegado: solo el SuperAdmin puede conmutar de base de datos.');
    header('Location: ' . url('index.php'));
    exit;
}

// 2. Sanitizar parámetro $_GET['bd'] (solo caracteres alfanuméricos y guiones bajos)
$bdSolicitada = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_GET['bd'] ?? ''));

if ($bdSolicitada === '') {
    flash('error', 'No se especificó ninguna base de datos.');
    header('Location: ' . url('index.php'));
    exit;
}

// 3. Caso especial: Retorno a la Base de Datos Máster Principal / Admin
if ($bdSolicitada === 'luarsoft' || $bdSolicitada === 'luarsoft_db_admin') {
    $_SESSION['tenant_db'] = 'luarsoft';
    $_SESSION['modo_soporte'] = false;
    $_SESSION['es_superadmin'] = true;
    unset($_SESSION['tenant_usuario_viendo']);

    flash('success', 'Has regresado a la base de datos principal máster.');
    session_write_close();
    header('Location: ' . url('index.php'));
    exit;
}

// 4. Verificación Directa en la Base de Datos Máster 'luarsoft'
$master = masterConexion();
$bdExiste = false;
$usuarioTenant = null;

// A. Comprobar en luarsoft.tenants (si la tabla existe)
$checkTenantsTable = @mysqli_query($master, "SHOW TABLES FROM `luarsoft` LIKE 'tenants'");
if ($checkTenantsTable && mysqli_num_rows($checkTenantsTable) > 0) {
    $stmtTenant = mysqli_prepare($master, "SELECT nombre_bd FROM `luarsoft`.`tenants` WHERE `nombre_bd` = ? AND (`estado` = 1 OR `estado` = '1' OR `estado` = 'activo') LIMIT 1");
    if ($stmtTenant) {
        mysqli_stmt_bind_param($stmtTenant, "s", $bdSolicitada);
        mysqli_stmt_execute($stmtTenant);
        $resTenant = mysqli_stmt_get_result($stmtTenant);
        if ($resTenant && $resTenant->fetch_assoc()) {
            $bdExiste = true;
        }
    }
}

// B. Comprobar en luarsoft.usuarios_sistema
if (!$bdExiste) {
    $stmtUser = mysqli_prepare($master, "SELECT nombre_bd_asignada, usuario FROM `luarsoft`.`usuarios_sistema` WHERE `nombre_bd_asignada` = ? AND `estado` = 'activo' LIMIT 1");
    if ($stmtUser) {
        mysqli_stmt_bind_param($stmtUser, "s", $bdSolicitada);
        mysqli_stmt_execute($stmtUser);
        $resUser = mysqli_stmt_get_result($stmtUser);
        if ($resUser && ($filaUser = $resUser->fetch_assoc())) {
            $bdExiste = true;
            $usuarioTenant = $filaUser['usuario'];
        }
    }
}

// C. Verificación en el servidor MySQL (SCHEMATA)
if (!$bdExiste) {
    $stmtSchema = mysqli_prepare($master, "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1");
    if ($stmtSchema) {
        mysqli_stmt_bind_param($stmtSchema, "s", $bdSolicitada);
        mysqli_stmt_execute($stmtSchema);
        $resSchema = mysqli_stmt_get_result($stmtSchema);
        if ($resSchema && $resSchema->fetch_assoc()) {
            $bdExiste = true;
        }
    }
}

// Si la base de datos no es válida o no existe
if (!$bdExiste) {
    flash('error', 'No se pudo cambiar a la base de datos solicitada.');
    header('Location: ' . url('index.php'));
    exit;
}

// 5. Asignación de Variables de Soporte SuperAdmin
$_SESSION['tenant_db'] = $bdSolicitada;
$_SESSION['modo_soporte'] = true;
$_SESSION['es_superadmin'] = true; // MANTENER facultades máster

if ($usuarioTenant !== null) {
    $_SESSION['tenant_usuario_viendo'] = $usuarioTenant;
}

// 6. Redirección Limpia con mensaje de éxito
flash('success', "Conectado exitosamente en Modo Soporte a la base de datos: {$bdSolicitada}");
session_write_close();
header('Location: ' . url('index.php'));
exit;
