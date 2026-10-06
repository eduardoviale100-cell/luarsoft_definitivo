<?php
/**
 * login.php
 * ------------------------------------------------------------------
 * Página de inicio de sesión unificada para LuarSoft ERP.
 *
 * FLUJO DE AUTENTICACIÓN MULTI-TENANT:
 *   1. Limpia cualquier sesión previa antes de procesar el POST.
 *   2. Busca primero en la BD central 'luarsoft' (usuarios SuperAdmin).
 *      → Si coincide: es_superadmin = true, tenant_db = 'luarsoft'
 *   3. Si no existe en BD central, busca en usuarios_sistema para
 *      obtener la BD del tenant, luego valida en esa BD local.
 *      → Si coincide: es_superadmin = false, rol y permisos reales
 *        del usuario dentro de su propia empresa.
 *   4. Finaliza con session_write_close() y redirige a index.php.
 */
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/permisos.php';
require_once __DIR__ . '/includes/tenant_manager.php';

$error = '';

// Asegurar que la tabla central y el usuario admin existan
inicializarSistemaMultiTenant();

// Si ya hay sesión activa válida, redirigir directamente al dashboard
if (!empty($_SESSION['usuario']) && !empty($_SESSION['id_usuario'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── PASO 0: Limpiar cualquier sesión o rol fantasma previo ──────────────
    $_SESSION = [];

    $usuarioInput = limpiar($_POST['usuario'] ?? '');
    $claveInput   = (string)($_POST['contraseña'] ?? ($_POST['contrasena'] ?? ''));

    if ($usuarioInput === '' || $claveInput === '') {
        $error = 'Debes ingresar usuario y contraseña.';
    } else {
        $master         = masterConexion();
        $usuarioValido  = null;
        $nombreBdTenant = 'luarsoft';
        $esSuperAdmin   = false;

        /**
         * Helper: Verifica contraseña soportando bcrypt, MD5 y texto plano (legacy).
         */
        $verificarClave = static function (string $input, string $hash): bool {
            if (password_verify($input, $hash))                                         { return true; }
            if (strlen($hash) === 32 && ctype_xdigit($hash) && md5($input) === $hash)  { return true; }
            if ($input === $hash)                                                        { return true; }
            return false;
        };

        // ── PASO 1: Buscar en la BD principal 'luarsoft' ─────────────────────
        // Tabla 'usuarios' de la BD central = usuarios SuperAdmin de la plataforma
        $stmtGlobal = mysqli_prepare($master,
            "SELECT id_usuario, usuario, contraseña, rol FROM usuarios WHERE usuario = ? LIMIT 1"
        );
        if ($stmtGlobal) {
            mysqli_stmt_bind_param($stmtGlobal, 's', $usuarioInput);
            mysqli_stmt_execute($stmtGlobal);
            $filaGlobal = mysqli_stmt_get_result($stmtGlobal)->fetch_assoc();

            if ($filaGlobal && $verificarClave($claveInput, $filaGlobal['contraseña'])) {
                // → Es un usuario SuperAdmin de la plataforma
                $esSuperAdmin   = true;
                $nombreBdTenant = 'luarsoft';
                $usuarioValido  = [
                    'id'       => (int)$filaGlobal['id_usuario'],
                    'usuario'  => $filaGlobal['usuario'],
                    'rol'      => 'superadmin',
                    'permisos' => null,
                ];
            }
        }

        // ── PASO 2: Buscar en usuarios_sistema (registro de tenants) ─────────
        // Si no se encontró como SuperAdmin, buscar el tenant asignado
        if (!$usuarioValido && !$error) {
            $stmtSistema = mysqli_prepare($master,
                "SELECT id, usuario, clave, nombre_bd_asignada, rol, estado FROM usuarios_sistema WHERE usuario = ? LIMIT 1"
            );
            if ($stmtSistema) {
                mysqli_stmt_bind_param($stmtSistema, 's', $usuarioInput);
                mysqli_stmt_execute($stmtSistema);
                $filaSistema = mysqli_stmt_get_result($stmtSistema)->fetch_assoc();

                if ($filaSistema && $verificarClave($claveInput, $filaSistema['clave'])) {
                    if ($filaSistema['estado'] !== 'activo') {
                        $error = 'Esta cuenta se encuentra inactiva. Comunícate con el Administrador.';
                    } else {
                        $nombreBdTenant = $filaSistema['nombre_bd_asignada'] ?? '';

                        // Conectar a la BD del tenant para obtener el rol y permisos REALES
                        // del usuario dentro de su propia empresa (puede ser 'Administrador',
                        // 'Cajero', 'Vendedor', etc.)
                        $connTenantLocal = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $nombreBdTenant);
                        if (!$connTenantLocal) {
                            $error = 'No se puede acceder a la base de datos de tu empresa. Contacta al administrador.';
                        } else {
                            mysqli_set_charset($connTenantLocal, 'utf8mb4');
                            $stmtLocal = mysqli_prepare($connTenantLocal,
                                "SELECT id_usuario, usuario, rol, permisos FROM usuarios WHERE usuario = ? LIMIT 1"
                            );
                            $filaLocal = null;
                            if ($stmtLocal) {
                                mysqli_stmt_bind_param($stmtLocal, 's', $filaSistema['usuario']);
                                mysqli_stmt_execute($stmtLocal);
                                $filaLocal = mysqli_stmt_get_result($stmtLocal)->fetch_assoc();
                            }
                            mysqli_close($connTenantLocal);

                            if ($filaLocal) {
                                $esSuperAdmin  = false;
                                $usuarioValido = [
                                    'id'       => (int)$filaLocal['id_usuario'],
                                    'usuario'  => $filaLocal['usuario'],
                                    'rol'      => $filaLocal['rol'],      // Rol REAL en la empresa
                                    'permisos' => $filaLocal['permisos'],
                                ];
                            } else {
                                // Registro en usuarios_sistema pero no en la BD local del tenant
                                $esSuperAdmin  = false;
                                $usuarioValido = [
                                    'id'       => (int)$filaSistema['id'],
                                    'usuario'  => $filaSistema['usuario'],
                                    'rol'      => 'Administrador',
                                    'permisos' => null,
                                ];
                            }
                        }
                    }
                }
            }
        }

        // ── PASO 3: Búsqueda exhaustiva en BDs de tenants ───────────────────
        // Si el usuario no existe en ningún registro central, buscar su nombre
        // directamente en las BDs de todos los tenants registrados.
        if (!$usuarioValido && !$error) {
            $resBds = mysqli_query($master, "SELECT nombre_bd_asignada FROM usuarios_sistema ORDER BY id");
            while ($resBds && ($rowBd = mysqli_fetch_assoc($resBds))) {
                $bdCandidata = $rowBd['nombre_bd_asignada'];
                $connCand = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $bdCandidata);
                if (!$connCand) { continue; }
                mysqli_set_charset($connCand, 'utf8mb4');

                // Detectar nombre de columna de contraseña (puede tener ñ)
                $resCols = mysqli_query($connCand, "SHOW COLUMNS FROM usuarios LIKE 'contras%'");
                $colClave = $resCols ? (mysqli_fetch_assoc($resCols)['Field'] ?? 'contraseña') : 'contraseña';

                $stmtCand = mysqli_prepare($connCand,
                    "SELECT id_usuario, usuario, `$colClave` as clave, rol, permisos FROM usuarios WHERE usuario = ? LIMIT 1"
                );
                if ($stmtCand) {
                    mysqli_stmt_bind_param($stmtCand, 's', $usuarioInput);
                    mysqli_stmt_execute($stmtCand);
                    $filaCand = mysqli_stmt_get_result($stmtCand)->fetch_assoc();
                    if ($filaCand && $verificarClave($claveInput, $filaCand['clave'])) {
                        $esSuperAdmin   = false;
                        $nombreBdTenant = $bdCandidata;
                        $usuarioValido  = [
                            'id'       => (int)$filaCand['id_usuario'],
                            'usuario'  => $filaCand['usuario'],
                            'rol'      => $filaCand['rol'] ?? 'Administrador',
                            'permisos' => $filaCand['permisos'] ?? null,
                        ];
                        mysqli_close($connCand);
                        break;
                    }
                }
                mysqli_close($connCand);
            }
        }

        // ── PASO 4: Inicializar variables de sesión y redirigir ──────────────
        if ($usuarioValido && !$error) {
            session_regenerate_id(true);

            $_SESSION['id_usuario']   = (int)$usuarioValido['id'];
            $_SESSION['usuario_id']   = (int)$usuarioValido['id'];  // alias de compatibilidad
            $_SESSION['usuario']      = $usuarioValido['usuario'];
            $_SESSION['rol']          = $esSuperAdmin ? 'superadmin' : $usuarioValido['rol'];
            $_SESSION['es_superadmin'] = $esSuperAdmin;
            $_SESSION['tenant_db']    = $nombreBdTenant;
            $_SESSION['permisos']     = $esSuperAdmin
                ? PERMISOS_ADMIN_COMPLETOS
                : decodificarPermisos($usuarioValido['permisos'] ?? null, $usuarioValido['rol']);

            // Forzar escritura en disco antes del redirect
            session_write_close();

            header('Location: index.php');
            exit;
        } elseif (!$error) {
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión · LuarSoft - Eros Tecnología</title>
    <link rel="icon" href="<?= url('assets/img/eros.jpg') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>

<div class="login-hero" style="background-image: url('<?= url('assets/img/tienda-eros.jpg') ?>');">

    <div class="login-hero-topbar">
        <img src="<?= url('assets/img/eros.jpg') ?>" alt="Eros">
        <span>Multiservicios Eros</span>
    </div>

    <div class="login-hero-center">
        <div class="login-hero-title">
            <h1>MULTISERVICIOS <span>EROS</span></h1>
            <p>"Soluciones rápidas y eficientes para tu equipo"</p>
        </div>

        <div class="login-hero-card">
            <img src="<?= url('assets/img/eros.jpg') ?>" alt="Eros Tecnología">
            <h3>INICIAR <span>SESIÓN</span></h3>
            <p class="subtitle">Bienvenido al panel de LuarSoft</p>

            <?php if ($error): ?>
                <div class="alert alert-danger text-center py-2" style="border-radius: var(--radius-sm); font-size:0.85rem;">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= url('login.php') ?>" autocomplete="off">
                <div class="field-group">
                    <label class="form-label">Usuario</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="usuario" class="form-control" placeholder="Tu usuario" required autofocus autocomplete="username">
                    </div>
                </div>
                <div class="field-group">
                    <label class="form-label">Contraseña</label>
                    <div class="input-group has-eye">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="contraseña" id="inputClave" class="form-control" placeholder="Tu contraseña" required autocomplete="new-password">
                        <button type="button" class="btn btn-eye" onclick="togglePasswordLogin()" tabindex="-1" aria-label="Mostrar u ocultar contraseña">
                            <i class="bi bi-eye" id="iconoOjoClave"></i>
                        </button>
                    </div>
                </div>

                <div class="login-hero-row">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="checkRecordarme" name="recordarme" value="1">
                        <label class="form-check-label" for="checkRecordarme">Recordarme</label>
                    </div>
                    <button type="button" class="login-forgot-link" data-bs-toggle="modal" data-bs-target="#modalOlvideClave">
                        ¿Olvidaste tu contraseña?
                    </button>
                </div>

                <button type="submit" class="btn-submit-login">
                    INICIAR SESIÓN <i class="bi bi-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>

    <div class="login-hero-footer">
        © <?= date('Y') ?> Multiservicios Eros · Sistema desarrollado por <strong>CWSSy</strong> | LuarSoft
    </div>
</div>

<!-- Modal informativo: recuperación de contraseña -->
<div class="modal fade" id="modalOlvideClave" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key"></i> ¿Olvidaste tu contraseña?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p>Por seguridad, las contraseñas de LuarSoft no se recuperan automáticamente desde esta pantalla.</p>
        <p>Comunícate con el <strong>Administrador del sistema</strong> para que restablezca tu acceso desde
        <em>Sistema &gt; Usuarios</em>.</p>
        <div class="d-flex align-items-center gap-2 mt-3 p-2" style="background:var(--n-100); border-radius:8px;">
            <i class="bi bi-whatsapp" style="font-size:1.3rem; color:#25D366;"></i>
            <div>
                <div class="fw-bold" style="font-size:0.9rem;">Contacto: Eros Tecnología</div>
                <div class="text-muted" style="font-size:0.85rem;">Cel. 949 092 352</div>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Entendido</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script>
function togglePasswordLogin() {
    const input = document.getElementById('inputClave');
    const icono = document.getElementById('iconoOjoClave');
    const mostrar = input.type === 'password';
    input.type = mostrar ? 'text' : 'password';
    icono.classList.toggle('bi-eye', !mostrar);
    icono.classList.toggle('bi-eye-slash', mostrar);
}
</script>
</body>
</html>
