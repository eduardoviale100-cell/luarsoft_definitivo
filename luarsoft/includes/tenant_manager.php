<?php
/**
 * includes/tenant_manager.php
 * ------------------------------------------------------------------
 * Gestor central de aprovisionamiento de bases de datos por usuario
 * (Arquitectura Multi-Tenant: Database-per-User).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/conexion.php';

/**
 * Retorna una conexión exclusiva a la base de datos central de control (luarsoft).
 */
function masterConexion(): mysqli
{
    static $masterConn = null;
    if ($masterConn instanceof mysqli && @mysqli_ping($masterConn)) {
        return $masterConn;
    }

    $masterConn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, 'luarsoft');
    if (!$masterConn) {
        // Si luarsoft aún no existe, conectar al servidor y crearla
        $serverConn = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
        if ($serverConn) {
            mysqli_query($serverConn, "CREATE DATABASE IF NOT EXISTS `luarsoft` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            mysqli_close($serverConn);
            $masterConn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, 'luarsoft');
        }
    }

    if (!$masterConn) {
        die("Error crítico: No se pudo conectar a la base de datos central luarsoft: " . mysqli_connect_error());
    }

    mysqli_set_charset($masterConn, 'utf8mb4');
    return $masterConn;
}

/**
 * Sanitiza un nombre de usuario para que sea seguro como identificador de base de datos.
 * Solo permite minúsculas, números y guión bajo (a-z0-9_).
 */
function sanitizarUsuarioTenant(string $usuario): string
{
    $u = strtolower(trim($usuario));
    // Reemplazar espacios y guiones por guión bajo
    $u = preg_replace('/[\s\-]+/', '_', $u);
    // Eliminar caracteres especiales y acentos
    $u = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $u);
    $u = preg_replace('/[^a-z0-9_]/', '', $u);
    $u = trim($u, '_');
    return $u !== '' ? $u : 'user';
}

/**
 * Devuelve el nombre de la BD para un usuario dado.
 * Ej: 'luarsoft_db_eduardo'
 */
function obtenerNombreBdTenant(string $usuario): string
{
    $clean = sanitizarUsuarioTenant($usuario);
    return 'luarsoft_db_' . $clean;
}

/**
 * Asegura que la tabla central usuarios_sistema y la BD del Admin existan.
 */
function inicializarSistemaMultiTenant(): void
{
    $master = masterConexion();

    // 1. Crear tabla usuarios_sistema en luarsoft
    $sqlTabla = "
    CREATE TABLE IF NOT EXISTS `usuarios_sistema` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `usuario` VARCHAR(50) UNIQUE NOT NULL,
        `nombre_completo` VARCHAR(100) NOT NULL,
        `clave` VARCHAR(255) NOT NULL,
        `nombre_bd_asignada` VARCHAR(64) UNIQUE NOT NULL,
        `rol` ENUM('Admin', 'Normal') DEFAULT 'Normal',
        `estado` ENUM('activo', 'inactivo') DEFAULT 'activo',
        `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    mysqli_query($master, $sqlTabla);

    // 2. Comprobar si existe el usuario admin
    $resAdmin = mysqli_query($master, "SELECT id, clave, nombre_bd_asignada FROM `usuarios_sistema` WHERE `usuario` = 'admin' LIMIT 1");
    if (!$resAdmin || mysqli_num_rows($resAdmin) === 0) {
        $hashAdmin = password_hash('admin123', PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($master, "
            INSERT INTO `usuarios_sistema` (`usuario`, `nombre_completo`, `clave`, `nombre_bd_asignada`, `rol`, `estado`)
            VALUES ('admin', 'Administrador Principal', ?, 'luarsoft_db_admin', 'Admin', 'activo')
        ");
        mysqli_stmt_bind_param($stmt, "s", $hashAdmin);
        mysqli_stmt_execute($stmt);
    }

    // 3. Asegurar que luarsoft_db_admin exista y tenga las tablas
    provisionarBdTenant('luarsoft_db_admin', 'admin', 'Administrador Principal', password_hash('admin123', PASSWORD_BCRYPT), 'Admin');
}

/**
 * Importa las 13 tablas en una base de datos específica.
 */
function importarTablasTenant(string $nombreBd): bool
{
    $templateFile = __DIR__ . '/../database/template_tenant.sql';
    if (!file_exists($templateFile)) {
        // Si no existe, crear la plantilla a partir de luarsoft.sql
        $orig = @file_get_contents(__DIR__ . '/../database/luarsoft.sql') ?: '';
        $clean = preg_replace('/^\s*CREATE\s+DATABASE\b[^\n]*;/im', '', $orig);
        $clean = preg_replace('/^\s*USE\b[^\n]*;/im', '', $clean);
        file_put_contents($templateFile, $clean);
    }

    // Intentar primero con mysql.exe (rápido y 100% fiel a sintaxis compleja)
    $mysqlBin = 'C:\\xampp\\mysql\\bin\\mysql.exe';
    if (file_exists($mysqlBin)) {
        $cmd = "\"{$mysqlBin}\" -u " . DB_USER . " --default-character-set=utf8mb4 {$nombreBd} < \"{$templateFile}\" 2>&1";
        exec("cmd /c {$cmd}", $output, $returnVar);
        if ($returnVar === 0) {
            return true;
        }
    }

    // Fallback: Ejecución vía PHP
    $connTenant = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $nombreBd);
    if (!$connTenant) {
        return false;
    }
    mysqli_set_charset($connTenant, 'utf8mb4');

    $sqlContent = file_get_contents($templateFile);
    if (empty($sqlContent)) {
        mysqli_close($connTenant);
        return false;
    }

    // Desactivar temporalmente foreign key checks
    mysqli_query($connTenant, "SET FOREIGN_KEY_CHECKS = 0");
    if (mysqli_multi_query($connTenant, $sqlContent)) {
        do {
            if ($res = mysqli_store_result($connTenant)) {
                mysqli_free_result($res);
            }
        } while (mysqli_more_results($connTenant) && mysqli_next_result($connTenant));
    }
    mysqli_query($connTenant, "SET FOREIGN_KEY_CHECKS = 1");
    mysqli_close($connTenant);

    return true;
}

/**
 * Crea físicamente la base de datos del usuario e inserta su esquema de 13 tablas.
 */
function provisionarBdTenant(string $nombreBd, string $usuario, string $nombreCompleto, string $hashClave, string $rol = 'Normal'): bool
{
    $serverConn = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
    if (!$serverConn) {
        return false;
    }

    // 1. Crear base de datos para el tenant
    $sqlCreate = "CREATE DATABASE IF NOT EXISTS `{$nombreBd}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if (!mysqli_query($serverConn, $sqlCreate)) {
        mysqli_close($serverConn);
        return false;
    }
    mysqli_close($serverConn);

    // 2. Importar tablas del sistema
    importarTablasTenant($nombreBd);

    // 3. Registrar al usuario dentro de su propia tabla `usuarios` local
    $connTenant = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $nombreBd);
    if ($connTenant) {
        mysqli_set_charset($connTenant, 'utf8mb4');
        $rolInterno = ($rol === 'Admin') ? 'Administrador' : 'Administrador'; // En su propia BD, el usuario es dueño total
        $stmt = mysqli_prepare($connTenant, "
            INSERT INTO `usuarios` (`usuario`, `rol`, `permisos`, `contraseña`)
            VALUES (?, ?, '', ?)
            ON DUPLICATE KEY UPDATE `contraseña` = VALUES(`contraseña`), `rol` = VALUES(`rol`)
        ");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sss", $usuario, $rolInterno, $hashClave);
            mysqli_stmt_execute($stmt);
        }
        mysqli_close($connTenant);
    }

    return true;
}

/**
 * Registra un nuevo usuario en luarsoft.usuarios_sistema y crea su BD dedicada.
 */
function crearUsuarioSistema(string $usuario, string $nombreCompleto, string $clave, string $rol = 'Normal'): array
{
    $master = masterConexion();

    $usuarioLimpio = trim($usuario);
    $nombreLimpio = trim($nombreCompleto);
    $cleanTag = sanitizarUsuarioTenant($usuarioLimpio);

    if ($cleanTag === '' || strlen($cleanTag) < 2) {
        return ['success' => false, 'error' => 'El nombre de usuario debe contener al menos 2 caracteres válidos (a-z, 0-9).'];
    }

    if (strlen($clave) < 4) {
        return ['success' => false, 'error' => 'La contraseña debe tener al menos 4 caracteres.'];
    }

    $nombreBdAsignada = 'luarsoft_db_' . $cleanTag;

    // Verificar si el usuario o la BD ya existen en usuarios_sistema
    $stmtCheck = mysqli_prepare($master, "SELECT id FROM `usuarios_sistema` WHERE `usuario` = ? OR `nombre_bd_asignada` = ? LIMIT 1");
    mysqli_stmt_bind_param($stmtCheck, "ss", $usuarioLimpio, $nombreBdAsignada);
    mysqli_stmt_execute($stmtCheck);
    if (mysqli_stmt_get_result($stmtCheck)->fetch_assoc()) {
        return ['success' => false, 'error' => "El usuario '{$usuarioLimpio}' o la base de datos '{$nombreBdAsignada}' ya existen."];
    }

    $hashClave = password_hash($clave, PASSWORD_BCRYPT);
    $rolValido = in_array($rol, ['Admin', 'Normal'], true) ? $rol : 'Normal';

    // 1. Aprovisionar físicamente la base de datos del usuario
    $okBd = provisionarBdTenant($nombreBdAsignada, $usuarioLimpio, $nombreLimpio, $hashClave, $rolValido);
    if (!$okBd) {
        return ['success' => false, 'error' => "No se pudo crear la base de datos {$nombreBdAsignada}. Verifique permisos de MySQL."];
    }

    // 2. Guardar en la base de datos central luarsoft.usuarios_sistema
    $stmtIns = mysqli_prepare($master, "
        INSERT INTO `usuarios_sistema` (`usuario`, `nombre_completo`, `clave`, `nombre_bd_asignada`, `rol`, `estado`)
        VALUES (?, ?, ?, ?, ?, 'activo')
    ");
    mysqli_stmt_bind_param($stmtIns, "sssss", $usuarioLimpio, $nombreLimpio, $hashClave, $nombreBdAsignada, $rolValido);

    if (mysqli_stmt_execute($stmtIns)) {
        return [
            'success' => true,
            'mensaje' => "Usuario '{$usuarioLimpio}' creado exitosamente con base de datos propia '{$nombreBdAsignada}'.",
            'nombre_bd' => $nombreBdAsignada,
        ];
    }

    return ['success' => false, 'error' => 'Error al registrar en usuarios_sistema: ' . mysqli_error($master)];
}

/**
 * Obtiene la lista de todos los usuarios registrados en el sistema central.
 */
function listarUsuariosSistema(): array
{
    $master = masterConexion();
    $lista = [];
    $res = mysqli_query($master, "SELECT * FROM `usuarios_sistema` ORDER BY `id` ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $lista[] = $row;
        }
    }
    return $lista;
}

/**
 * Permite al Administrador cambiar de base de datos activa (Modo Soporte).
 */
function cambiarTenantActivo(string $nuevaBd): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Solo un Administrador puede cambiar de base de datos
    $rol = $_SESSION['rol'] ?? '';
    if (!in_array($rol, ['Admin', 'Administrador'], true)) {
        return false;
    }

    $master = masterConexion();
    $stmt = mysqli_prepare($master, "SELECT nombre_bd_asignada, usuario FROM `usuarios_sistema` WHERE `nombre_bd_asignada` = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $nuevaBd);
    mysqli_stmt_execute($stmt);
    $fila = mysqli_stmt_get_result($stmt)->fetch_assoc();

    if ($fila) {
        $_SESSION['tenant_db'] = $fila['nombre_bd_asignada'];
        $_SESSION['tenant_usuario_viendo'] = $fila['usuario'];
        return true;
    }

    return false;
}

/**
 * Resetea la contraseña de un usuario en el sistema central y en su BD propia.
 */
function resetearClaveTenant(int $idUsuario, string $nuevaClave): bool
{
    if (strlen($nuevaClave) < 4) {
        return false;
    }
    $master = masterConexion();
    $stmt = mysqli_prepare($master, "SELECT usuario, nombre_bd_asignada FROM `usuarios_sistema` WHERE `id` = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $idUsuario);
    mysqli_stmt_execute($stmt);
    $user = mysqli_stmt_get_result($stmt)->fetch_assoc();
    if (!$user) {
        return false;
    }

    $hash = password_hash($nuevaClave, PASSWORD_BCRYPT);
    $stmtUp = mysqli_prepare($master, "UPDATE `usuarios_sistema` SET `clave` = ? WHERE `id` = ?");
    mysqli_stmt_bind_param($stmtUp, "si", $hash, $idUsuario);
    $ok = mysqli_stmt_execute($stmtUp);

    // Sincronizar en la BD tenant local si existe
    if ($ok && !empty($user['nombre_bd_asignada'])) {
        $connTenant = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $user['nombre_bd_asignada']);
        if ($connTenant) {
            $stmtTen = mysqli_prepare($connTenant, "UPDATE `usuarios` SET `contraseña` = ? WHERE `usuario` = ?");
            if ($stmtTen) {
                mysqli_stmt_bind_param($stmtTen, "ss", $hash, $user['usuario']);
                mysqli_stmt_execute($stmtTen);
            }
            mysqli_close($connTenant);
        }
    }

    return $ok;
}

/**
 * Cambia el estado del usuario entre 'activo' e 'inactivo'.
 * No permite suspender al admin principal.
 */
function toggleEstadoTenant(int $idUsuario): array
{
    $master = masterConexion();
    $stmt = mysqli_prepare($master, "SELECT usuario, rol, estado FROM `usuarios_sistema` WHERE `id` = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $idUsuario);
    mysqli_stmt_execute($stmt);
    $user = mysqli_stmt_get_result($stmt)->fetch_assoc();
    if (!$user) {
        return ['success' => false, 'error' => 'Usuario no encontrado.'];
    }

    if ($user['rol'] === 'Admin' || $user['usuario'] === 'admin') {
        return ['success' => false, 'error' => 'No se puede desactivar la cuenta del Administrador Principal.'];
    }

    $nuevoEstado = ($user['estado'] === 'activo') ? 'inactivo' : 'activo';
    $stmtUp = mysqli_prepare($master, "UPDATE `usuarios_sistema` SET `estado` = ? WHERE `id` = ?");
    mysqli_stmt_bind_param($stmtUp, "si", $nuevoEstado, $idUsuario);
    if (mysqli_stmt_execute($stmtUp)) {
        return ['success' => true, 'nuevo_estado' => $nuevoEstado, 'usuario' => $user['usuario']];
    }

    return ['success' => false, 'error' => 'Error al actualizar el estado: ' . mysqli_error($master)];
}

/**
 * Obtiene métricas y estadísticas de tamaño y registros de una BD tenant.
 */
function obtenerStatsTenant(string $nombreBd): array
{
    $master = masterConexion();
    $stats = [
        'nombre_bd' => $nombreBd,
        'tamano_mb' => 0.0,
        'tablas' => 0,
        'productos' => 0,
        'clientes' => 0,
        'ventas' => 0,
        'ordenes' => 0,
        'existe' => false
    ];

    // Verificar en information_schema
    $sqlSize = "
        SELECT 
            COUNT(table_name) AS total_tablas,
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS tamano_mb
        FROM information_schema.tables 
        WHERE table_schema = ?
    ";
    $stmt = mysqli_prepare($master, $sqlSize);
    mysqli_stmt_bind_param($stmt, "s", $nombreBd);
    mysqli_stmt_execute($stmt);
    $rowSize = mysqli_stmt_get_result($stmt)->fetch_assoc();

    if ($rowSize && $rowSize['total_tablas'] > 0) {
        $stats['existe'] = true;
        $stats['tamano_mb'] = (float)($rowSize['tamano_mb'] ?? 0.0);
        $stats['tablas'] = (int)($rowSize['total_tablas'] ?? 0);

        // Contar registros de negocio
        $connTenant = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $nombreBd);
        if ($connTenant) {
            $qProd = mysqli_query($connTenant, "SELECT COUNT(*) c FROM `productos`");
            $stats['productos'] = (int)(mysqli_fetch_assoc($qProd)['c'] ?? 0);

            $qCli = mysqli_query($connTenant, "SELECT COUNT(*) c FROM `clientes`");
            $stats['clientes'] = (int)(mysqli_fetch_assoc($qCli)['c'] ?? 0);

            $qVentas = mysqli_query($connTenant, "SELECT COUNT(*) c FROM `ventas`");
            $stats['ventas'] = (int)(mysqli_fetch_assoc($qVentas)['c'] ?? 0);

            $qOrd = mysqli_query($connTenant, "SELECT COUNT(*) c FROM `ordenes`");
            $stats['ordenes'] = (int)(mysqli_fetch_assoc($qOrd)['c'] ?? 0);

            mysqli_close($connTenant);
        }
    }

    return $stats;
}

/**
 * Re-inicializa la base de datos de un usuario a estado de fábrica (Factory Reset).
 */
function reinicializarBdTenant(string $nombreBd): bool
{
    // Proteger la BD central de un reset accidental
    if ($nombreBd === 'luarsoft') {
        return false;
    }

    $serverConn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS);
    if (!$serverConn) {
        return false;
    }

    // Recrear la base de datos limpia
    mysqli_query($serverConn, "DROP DATABASE IF EXISTS `{$nombreBd}`");
    mysqli_query($serverConn, "CREATE DATABASE `{$nombreBd}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    mysqli_close($serverConn);

    // Re-importar el template limpio
    return importarTablasTenant($nombreBd);
}

/**
 * Elimina un usuario del sistema y opcionalmente destruye su base de datos física.
 * Flujo de borrado seguro con validación estricta por regex y protección de BDs maestras.
 */
function eliminarTenantCompleto(int $idUsuario, bool $borrarBd = false): array
{
    $master = masterConexion();
    $stmt = mysqli_prepare($master, "SELECT usuario, rol, nombre_bd_asignada FROM `usuarios_sistema` WHERE `id` = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $idUsuario);
    mysqli_stmt_execute($stmt);
    $user = mysqli_stmt_get_result($stmt)->fetch_assoc();

    if (!$user) {
        return ['success' => false, 'error' => 'Usuario no encontrado.'];
    }

    if ($user['rol'] === 'Admin' || strtolower($user['usuario']) === 'admin') {
        return ['success' => false, 'error' => 'No es posible eliminar la cuenta del Administrador Principal.'];
    }

    $nombreBd = trim($user['nombre_bd_asignada']);
    $bdBorradaFisicamente = false;

    // Si se solicitó eliminar físicamente la base de datos MySQL
    if ($borrarBd && !empty($nombreBd)) {
        // Lista negra de bases de datos protegidas (Bases maestras y del sistema)
        $bdProtegidas = ['luarsoft', 'luarsoft_db_admin', 'information_schema', 'mysql', 'performance_schema', 'sys'];

        // Comprobar formato estrictamente con Regex: /^luarsoft_db_[a-zA-Z0-9_]+$/
        $esRegexValida = (bool)preg_match('/^luarsoft_db_[a-zA-Z0-9_]+$/', $nombreBd);
        $esProtegida = in_array(strtolower($nombreBd), $bdProtegidas, true);

        if (!$esRegexValida) {
            return ['success' => false, 'error' => "El nombre de la base de datos '{$nombreBd}' no cumple con la sintaxis de seguridad requerida."];
        }

        if ($esProtegida) {
            return ['success' => false, 'error' => "Operación denegada: La base de datos '{$nombreBd}' es una base de datos maestra protegida."];
        }

        // Ejecutar DROP DATABASE seguro
        $serverConn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS);
        if ($serverConn) {
            $nombreBdEscaped = mysqli_real_escape_string($serverConn, $nombreBd);
            $sqlDrop = "DROP DATABASE IF EXISTS `{$nombreBdEscaped}`";
            if (mysqli_query($serverConn, $sqlDrop)) {
                $bdBorradaFisicamente = true;
            }
            mysqli_close($serverConn);
        }
    }

    // Eliminar el registro central de usuarios_sistema
    $stmtDel = mysqli_prepare($master, "DELETE FROM `usuarios_sistema` WHERE `id` = ?");
    mysqli_stmt_bind_param($stmtDel, "i", $idUsuario);
    $ok = mysqli_stmt_execute($stmtDel);

    if (!$ok) {
        return ['success' => false, 'error' => 'Error al eliminar el registro central: ' . mysqli_error($master)];
    }

    // Si el admin estaba conectado a esa BD en sesión, resetear a luarsoft_db_admin
    if (isset($_SESSION['tenant_db']) && $_SESSION['tenant_db'] === $nombreBd) {
        $_SESSION['tenant_db'] = 'luarsoft_db_admin';
    }

    return ['success' => true, 'usuario' => $user['usuario'], 'bd_borrada' => $bdBorradaFisicamente];
}
