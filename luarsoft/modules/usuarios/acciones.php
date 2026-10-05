<?php
/**
 * modules/usuarios/acciones.php
 * ------------------------------------------------------------------
 * Procesador central de acciones Multi-Tenant del módulo de Usuarios:
 * - Resetear Contraseña (Bcrypt)
 * - Suspender / Activar cuenta (Toggle Estado)
 * - Descargar Backup SQL (Dump completo)
 * - Consultar Estadísticas (Tamaño en MB y conteo de tablas/registros)
 * - Re-inicializar Base de Datos (Factory Reset)
 * - Eliminar Usuario (con opción de DROP DATABASE físico)
 */
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tenant_manager.php';

// Validación estricta: Solo el Administrador Master puede ejecutar estas operaciones
if (!esAdministrador()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Acceso denegado. Se requieren privilegios de Administrador.']));
}

$accion = $_REQUEST['accion'] ?? '';

switch ($accion) {

    // 1. OBTENER STATS DE LA BD (JSON para Modal)
    case 'stats':
        header('Content-Type: application/json; charset=utf-8');
        $nombreBd = trim($_GET['bd'] ?? '');
        if ($nombreBd === '') {
            echo json_encode(['success' => false, 'error' => 'Nombre de base de datos no especificado.']);
            exit;
        }
        $stats = obtenerStatsTenant($nombreBd);
        echo json_encode(['success' => true, 'data' => $stats]);
        exit;

    // 2. DESCARGAR BACKUP SQL
    case 'backup':
        $nombreBd = trim($_GET['bd'] ?? '');
        if ($nombreBd === '') {
            flash('error', 'Base de datos no especificada para el backup.');
            header('Location: ' . url('modules/usuarios/listado.php'));
            exit;
        }

        $filename = "backup_{$nombreBd}_" . date('Y-m-d_H-i-s') . ".sql";
        $mysqlDumpBin = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';

        if (file_exists($mysqlDumpBin)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');

            $cmd = "\"{$mysqlDumpBin}\" -u " . DB_USER . " --opt --default-character-set=utf8mb4 {$nombreBd} 2>&1";
            passthru("cmd /c {$cmd}");
            exit;
        } else {
            flash('error', 'La utilidad mysqldump no se encontró en el servidor.');
            header('Location: ' . url('modules/usuarios/listado.php'));
            exit;
        }

    // 3. RESETEAR CONTRASEÑA
    case 'reset_clave':
        $idUsuario = (int)($_POST['id_usuario'] ?? 0);
        $nuevaClave = (string)($_POST['nueva_clave'] ?? '');
        $confirmar = (string)($_POST['confirmar_clave'] ?? '');

        if ($idUsuario <= 0 || $nuevaClave === '') {
            flash('error', 'Debes ingresar una contraseña válida.');
        } elseif (strlen($nuevaClave) < 4) {
            flash('error', 'La contraseña debe tener un mínimo de 4 caracteres.');
        } elseif ($nuevaClave !== $confirmar) {
            flash('error', 'Las contraseñas no coinciden.');
        } else {
            if (resetearClaveTenant($idUsuario, $nuevaClave)) {
                flash('success', 'Contraseña actualizada y encriptada con Bcrypt exitosamente.');
            } else {
                flash('error', 'No se pudo actualizar la contraseña.');
            }
        }
        header('Location: ' . url('modules/usuarios/listado.php'));
        exit;

    // 4. TOGGLE ESTADO (SUSPENDER / ACTIVAR)
    case 'toggle_estado':
        $idUsuario = (int)($_REQUEST['id_usuario'] ?? 0);
        if ($idUsuario <= 0) {
            flash('error', 'ID de usuario inválido.');
        } else {
            $res = toggleEstadoTenant($idUsuario);
            if ($res['success']) {
                $estadoTxt = ($res['nuevo_estado'] === 'activo') ? 'activada' : 'suspendida';
                flash('success', "La cuenta '{$res['usuario']}' ha sido {$estadoTxt} correctamente.");
            } else {
                flash('error', $res['error']);
            }
        }
        header('Location: ' . url('modules/usuarios/listado.php'));
        exit;

    // 5. FACTORY RESET (RE-INICIALIZAR BD)
    case 'factory_reset':
        $nombreBd = trim($_POST['nombre_bd'] ?? '');
        $confirmacion = trim($_POST['confirmar_reset'] ?? '');

        if ($confirmacion !== 'RESETEAR') {
            flash('error', 'Debes escribir la palabra exacta "RESETEAR" para confirmar el reinicio de fábrica.');
        } elseif ($nombreBd === '' || $nombreBd === 'luarsoft') {
            flash('error', 'Operación cancelada: No se puede reiniciar la base de datos central de control.');
        } else {
            if (reinicializarBdTenant($nombreBd)) {
                flash('success', "La base de datos '{$nombreBd}' fue restaurada a su estado de fábrica inicial con éxito.");
            } else {
                flash('error', "Ocurrió un error al intentar re-inicializar la base de datos.");
            }
        }
        header('Location: ' . url('modules/usuarios/listado.php'));
        exit;

    // 6. ELIMINAR USUARIO (CON O SIN DROP DATABASE)
    case 'eliminar':
        $idUsuario = (int)($_POST['id_usuario'] ?? 0);
        $borrarBd = !empty($_POST['borrar_bd_fisica']);

        if ($idUsuario <= 0) {
            flash('error', 'ID de usuario inválido.');
        } else {
            $res = eliminarTenantCompleto($idUsuario, $borrarBd);
            if ($res['success']) {
                $msg = "Usuario '{$res['usuario']}' eliminado del sistema.";
                if ($res['bd_borrada']) {
                    $msg .= " La base de datos física también fue destruida.";
                }
                flash('success', $msg);
            } else {
                flash('error', $res['error']);
            }
        }
        header('Location: ' . url('modules/usuarios/listado.php'));
        exit;

    default:
        flash('error', 'Acción no reconocida.');
        header('Location: ' . url('modules/usuarios/listado.php'));
        exit;
}
