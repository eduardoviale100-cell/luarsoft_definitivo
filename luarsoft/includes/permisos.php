<?php
/**
 * includes/permisos.php — NUEVO.
 * ------------------------------------------------------------------
 * Catálogo central de módulos del sistema (para la Matriz de Permisos
 * de "Gestión de Usuarios") y funciones de verificación de acceso por
 * Rol. Se incluye desde includes/auth.php (bloqueo por URL directa) y
 * desde includes/sidebar.php (ocultar ítems de menú no permitidos).
 *
 * Diseño NO intrusivo: ningún módulo existente fue modificado para
 * usar esto. auth.php ya se incluye en todas las páginas protegidas,
 * así que basta con ampliarlo para que, además de exigir sesión
 * iniciada, verifique el permiso del módulo actual.
 *
 * Un usuario con rol 'Administrador' SIEMPRE tiene acceso total a
 * todo el sistema (cero pérdida de funciones para la cuenta admin
 * existente). Los permisos por checkbox solo aplican al rol
 * 'Cajero / Usuario'.
 */

const MODULOS_SISTEMA = [
    'dashboard' => ['label' => 'Panel Principal / Dashboard', 'icon' => 'bi-house-door'],
    'pos'       => ['label' => 'Punto de Venta (POS)', 'icon' => 'bi-cart-check-fill'],
    'clientes'  => ['label' => 'Clientes (Ver / Crear / Editar)', 'icon' => 'bi-people'],
    'productos' => ['label' => 'Productos (Ver / Crear / Editar)', 'icon' => 'bi-box-seam'],
    'tecnicos'  => ['label' => 'Técnicos', 'icon' => 'bi-person-gear'],
    'ordenes'   => ['label' => 'Órdenes de Reparación', 'icon' => 'bi-laptop'],
    'ventas'    => ['label' => 'Ventas', 'icon' => 'bi-receipt'],
    'reportes'  => ['label' => 'Reportes (Ver Métricas y Gráficos)', 'icon' => 'bi-graph-up-arrow'],
    'usuarios'  => ['label' => 'Gestión de Usuarios y Sistema', 'icon' => 'bi-person-lock'],
];

/** Módulos marcados por defecto para una cuenta nueva de tipo Cajero / Usuario. */
const PERMISOS_CAJERO_DEFECTO = ['dashboard', 'pos'];

/**
 * Lista completa de permisos para un Administrador.
 * Se usa al provisionar tenants, en migraciones retroactivas y al
 * hidratar sesiones para asegurar que nunca quede un Admin sin acceso.
 */
const PERMISOS_ADMIN_COMPLETOS = [
    'dashboard', 'pos', 'clientes', 'productos', 'tecnicos',
    'ordenes', 'ventas', 'compras', 'reportes', 'usuarios', 'configuracion',
];

/** Cadena ya formateada para INSERT/UPDATE directo en BD. */
const PERMISOS_ADMIN_COMPLETOS_STR = 'dashboard,pos,clientes,productos,tecnicos,ordenes,ventas,compras,reportes,usuarios,configuracion';

/** Convierte el string de permisos guardado en BD (separado por comas) a array. */
function decodificarPermisos(?string $permisosStr, ?string $rol = null): array
{
    // SuperAdmin y Administrador locales siempre tienen acceso total
    if ($rol !== null && in_array($rol, ['superadmin', 'Admin', 'Administrador'], true)) {
        return PERMISOS_ADMIN_COMPLETOS;
    }
    // Si el valor está vacío sin rol reconocido, devolver array vacío
    if (!$permisosStr || trim($permisosStr) === '') { return []; }
    return array_values(array_filter(array_map('trim', explode(',', $permisosStr))));
}

/** Convierte un array de claves de módulo al string separado por comas para guardar en BD. */
function codificarPermisos(array $permisos): string
{
    $validos = array_intersect($permisos, array_keys(MODULOS_SISTEMA));
    return implode(',', $validos);
}

/**
 * true si el usuario en sesión es SuperAdmin GLOBAL de la plataforma
 * (acceso a gestión de tenants, BD central, configuración del sistema).
 */
function esSuperAdmin(): bool
{
    return ($_SESSION['es_superadmin'] ?? false) === true
        || ($_SESSION['rol'] ?? '') === 'superadmin';
}

/**
 * true si el usuario es Administrador (local o global).
 * Un Administrador local gestiona su propia empresa.
 * Un SuperAdmin gestiona toda la plataforma.
 * Ambos tienen acceso total a los módulos operativos del ERP.
 */
function esAdministrador(): bool
{
    $rol = $_SESSION['rol'] ?? '';
    return in_array($rol, ['superadmin', 'Admin', 'Administrador'], true);
}

/** true si el usuario en sesión puede acceder al módulo indicado. */
function tienePermiso(string $modulo): bool
{
    // El Administrador SIEMPRE tiene acceso total a todos los módulos
    if (esAdministrador()) {
        return true;
    }
    // Para usuarios no-admin, verificar si el módulo está asignado en su lista de permisos
    $permisos = $_SESSION['permisos'] ?? [];
    if (is_string($permisos)) {
        $permisos = decodificarPermisos($permisos);
    }
    return in_array($modulo, $permisos, true);
}

/**
 * Determina a qué módulo del catálogo pertenece el script PHP que se
 * está ejecutando actualmente, a partir de su ruta. Devuelve null
 * para páginas que no forman parte de la matriz (login, logout, etc.)
 * y por lo tanto no requieren verificación de permisos.
 *
 * Nota PHP 7.4: se usa strpos() en vez de str_contains()/str_ends_with()
 * (disponibles solo desde PHP 8) para mantener compatibilidad con XAMPP
 * en versiones 7.4, tal como el resto del sistema.
 */
function moduloActual(): ?string
{
    $ruta = $_SERVER['SCRIPT_NAME'] ?? '';

    $terminaCon = function (string $ruta, string $sufijo): bool {
        return substr($ruta, -strlen($sufijo)) === $sufijo;
    };
    $contiene = function (string $ruta, string $trozo): bool {
        return strpos($ruta, $trozo) !== false;
    };

    // Endpoints AJAX compartidos entre el POS y su propio módulo de gestión.
    if ($terminaCon($ruta, '/modules/ventas/guardar_venta.php')) { return 'pos'; }
    if ($terminaCon($ruta, '/modules/clientes/buscar_doc.php')) { return 'pos_o_clientes'; }
    if ($terminaCon($ruta, '/modules/productos/buscar_autocomplete.php')) { return 'pos_o_productos'; }

    if ($terminaCon($ruta, '/modules/ventas/pos.php')) { return 'pos'; }
    if ($contiene($ruta, '/modules/ventas/')) { return 'ventas'; }
    if ($contiene($ruta, '/modules/clientes/')) { return 'clientes'; }
    if ($contiene($ruta, '/modules/productos/')) { return 'productos'; }
    if ($contiene($ruta, '/modules/compras/')) { return 'productos'; }
    if ($contiene($ruta, '/modules/tecnicos/')) { return 'tecnicos'; }
    if ($contiene($ruta, '/modules/ordenes/')) { return 'ordenes'; }
    if ($contiene($ruta, '/modules/reportes/')) { return 'reportes'; }
    if ($contiene($ruta, '/modules/usuarios/')) { return 'usuarios'; }
    if ($contiene($ruta, '/admin/')) { return 'usuarios'; }
    if ($terminaCon($ruta, '/index.php')) { return 'dashboard'; }

    return null;
}

/** URL del primer módulo al que el usuario en sesión sí tiene acceso (fallback tras bloqueo). */
function primeraRutaPermitida(): string
{
    if (esAdministrador()) { return 'index.php'; }

    $permisos = $_SESSION['permisos'] ?? [];
    $rutas = [
        'dashboard' => 'index.php',
        'pos'       => 'modules/ventas/pos.php',
        'clientes'  => 'modules/clientes/listado.php',
        'productos' => 'modules/productos/listado.php',
        'tecnicos'  => 'modules/tecnicos/listado.php',
        'ordenes'   => 'modules/ordenes/listado.php',
        'ventas'    => 'modules/ventas/lista_boletas.php',
        'reportes'  => 'modules/reportes/productos_mas_vendidos.php',
        'usuarios'  => 'modules/usuarios/listado.php',
    ];
    foreach ($rutas as $mod => $r) {
        if (in_array($mod, $permisos, true)) { return $r; }
    }
    return 'login.php';
}

/**
 * Verifica el acceso al módulo del script actual; si el usuario en
 * sesión no tiene permiso, lo redirige a la primera sección permitida
 * (o de vuelta al login si no tiene ninguna) con un mensaje flash.
 * No hace nada en páginas fuera de la matriz (retorno null).
 */
function verificarPermisoActual(): void
{
    $modulo = moduloActual();
    if ($modulo === null) { return; }

    if ($modulo === 'pos_o_clientes') {
        if (tienePermiso('pos') || tienePermiso('clientes')) { return; }
    } elseif ($modulo === 'pos_o_productos') {
        if (tienePermiso('pos') || tienePermiso('productos')) { return; }
    } elseif (tienePermiso($modulo)) {
        return;
    }

    flash('error', 'No tienes permiso para acceder a esa sección del sistema.');
    header('Location: ' . url(primeraRutaPermitida()));
    exit;
}
