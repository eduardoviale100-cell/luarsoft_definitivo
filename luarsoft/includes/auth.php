<?php
/**
 * includes/auth.php
 * ------------------------------------------------------------------
 * Debe incluirse (después de config/conexion.php) en cada página
 * protegida del sistema. Si no hay sesión de usuario activa,
 * redirige al login. Esto no existía de forma explícita en el
 * sistema original (ninguna página validaba la sesión), por lo que
 * es una mejora de seguridad que NO elimina ninguna funcionalidad:
 * simplemente exige haber iniciado sesión antes de operar el ERP,
 * tal como se espera de un sistema con login.
 *
 * Ampliación (Roles y Matriz de Permisos): además de exigir sesión,
 * ahora también verifica que el rol/permisos del usuario en sesión
 * le den acceso al módulo correspondiente al script actual. Un
 * Administrador siempre tiene acceso total; un Cajero / Usuario solo
 * a los módulos marcados en su matriz de permisos. Si intenta entrar
 * escribiendo la URL directamente, se le redirige a una sección a la
 * que sí tenga acceso.
 */

if (empty($_SESSION['usuario']) || !is_string($_SESSION['usuario'])) {
    // Session corrupted or missing — force re-login
    unset($_SESSION['usuario'], $_SESSION['id_usuario'], $_SESSION['rol'], $_SESSION['permisos']);
    header('Location: ' . url('login.php'));
    exit;
}

require_once __DIR__ . '/permisos.php';

if (!isset($_SESSION['rol']) && !empty($_SESSION['usuario'])) {
    require_once __DIR__ . '/tenant_manager.php';
    $master = masterConexion();
    $stmtSesion = mysqli_prepare($master, "SELECT rol, nombre_bd_asignada FROM usuarios_sistema WHERE usuario = ? LIMIT 1");
    if ($stmtSesion) {
        mysqli_stmt_bind_param($stmtSesion, "s", $_SESSION['usuario']);
        mysqli_stmt_execute($stmtSesion);
        $filaSesion = mysqli_stmt_get_result($stmtSesion)->fetch_assoc();
        $_SESSION['rol'] = $filaSesion['rol'] ?? 'Normal';
        if (empty($_SESSION['tenant_db'])) {
            $_SESSION['tenant_db'] = $filaSesion['nombre_bd_asignada'] ?? 'luarsoft';
        }
    } else {
        $_SESSION['rol'] = 'Normal';
    }
}

verificarPermisoActual();
