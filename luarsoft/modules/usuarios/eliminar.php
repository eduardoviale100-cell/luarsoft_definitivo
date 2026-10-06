<?php
/**
 * modules/usuarios/eliminar.php
 * ------------------------------------------------------------------
 * Eliminación definitiva de un tenant y borrado físico de su base de datos MySQL.
 * Exclusivo para el SuperAdmin.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permisos.php';

// 1. Verificación de Seguridad: Únicamente SuperAdmin
if (empty($_SESSION['es_superadmin']) || $_SESSION['es_superadmin'] !== true) {
    header('Location: ../../index.php');
    exit;
}

// 2. Desenganche de la BD (Paso Crítico para liberar MySQL)
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("USE `luarsoft`");
} catch (PDOException $e) {
    flash('error', 'Error al conectar con la base de datos central: ' . $e->getMessage());
    header('Location: ' . url('modules/usuarios/listado.php'));
    exit;
}

// 3. Lectura de Datos y Detección de la BD
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'ID de tenant no válido.');
    header('Location: ' . url('modules/usuarios/listado.php'));
    exit;
}

$tenant = null;
$nombreBd = '';
$usuario = '';

// Consulta del registro en 'luarsoft.tenants'
try {
    $stmtTenant = $pdo->prepare("SELECT * FROM `luarsoft`.`tenants` WHERE id = ? LIMIT 1");
    $stmtTenant->execute([$id]);
    $tenant = $stmtTenant->fetch();
} catch (Exception $e) {
    $tenant = null;
}

// Respaldo de consulta en 'luarsoft.usuarios_sistema' si no está en 'tenants'
if (!$tenant) {
    try {
        $stmtSys = $pdo->prepare("SELECT * FROM `luarsoft`.`usuarios_sistema` WHERE id = ? LIMIT 1");
        $stmtSys->execute([$id]);
        $tenant = $stmtSys->fetch();
    } catch (Exception $e) {
        $tenant = null;
    }
}

// Respaldo de consulta en 'luarsoft.usuarios'
if (!$tenant) {
    try {
        $stmtUsr = $pdo->prepare("SELECT * FROM `luarsoft`.`usuarios` WHERE id = ? LIMIT 1");
        $stmtUsr->execute([$id]);
        $tenant = $stmtUsr->fetch();
    } catch (Exception $e) {
        $tenant = null;
    }
}

if ($tenant) {
    $nombreBd = $tenant['nombre_bd'] ?? $tenant['nombre_bd_asignada'] ?? '';
    $usuario = $tenant['usuario'] ?? $tenant['nombre'] ?? '';
}

// Protección contra eliminación del Admin Principal o bases maestras
if (strtolower($usuario) === 'admin' || $nombreBd === 'luarsoft_db_admin' || $nombreBd === 'luarsoft') {
    flash('error', 'Operación cancelada: No se puede eliminar la cuenta de administración principal.');
    header('Location: ' . url('modules/usuarios/listado.php'));
    exit;
}

// 4. Borrado Físico Atómico de la Base de Datos
$bdsProtegidas = ['luarsoft', 'luarsoft_db_admin', 'mysql', 'information_schema', 'performance_schema', 'sys', 'luarsoft_master'];

if (!empty($nombreBd) && !in_array(strtolower($nombreBd), $bdsProtegidas, true)) {
    $nombreBdSanitizada = preg_replace('/[^a-zA-Z0-9_]/', '', $nombreBd);
    if (!empty($nombreBdSanitizada)) {
        try {
            $pdo->exec("DROP DATABASE IF EXISTS `$nombreBdSanitizada`");
        } catch (PDOException $e) {
            // Continuar con la limpieza de tablas maestras
        }
    }
}

// 5. Limpieza de Tablas Centrales y Redirección
try {
    $pdo->prepare("DELETE FROM `luarsoft`.`tenants` WHERE id = ? OR nombre_bd = ?")->execute([$id, $nombreBd]);
} catch (Exception $e) {}

try {
    $pdo->prepare("DELETE FROM `luarsoft`.`usuarios_sistema` WHERE id = ? OR nombre_bd_asignada = ?")->execute([$id, $nombreBd]);
} catch (Exception $e) {}

try {
    if (!empty($usuario)) {
        $pdo->prepare("DELETE FROM `luarsoft`.`usuarios` WHERE id = ? OR usuario = ?")->execute([$id, $usuario]);
    } else {
        $pdo->prepare("DELETE FROM `luarsoft`.`usuarios` WHERE id = ?")->execute([$id]);
    }
} catch (Exception $e) {}

// Si el SuperAdmin estaba actualmente inspeccionando esa BD, resetear la sesión a luarsoft
if (isset($_SESSION['tenant_db']) && $_SESSION['tenant_db'] === $nombreBd) {
    $_SESSION['tenant_db'] = 'luarsoft';
    $_SESSION['modo_soporte'] = false;
    unset($_SESSION['tenant_usuario_viendo']);
}

flash('success', "El tenant y su base de datos física '{$nombreBd}' fueron eliminados exitosamente.");
header('Location: ' . url('modules/usuarios/listado.php'));
exit;
