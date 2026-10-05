<?php
/**
 * modules/usuarios/nuevo.php
 * ------------------------------------------------------------------
 * Registro de Nuevo Usuario Normal con Aprovisionamiento Automático
 * de Base de Datos Propia (Database-per-User).
 */
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permisos.php';
require_once __DIR__ . '/../../includes/tenant_manager.php';

// Solo el Administrador puede registrar nuevos usuarios
if (!esAdministrador()) {
    flash('error', 'Acceso denegado: este módulo es exclusivo del Administrador.');
    header('Location: ' . url('index.php'));
    exit;
}

$error = '';

if (isset($_POST['guardar'])) {
    $nombreCompleto = trim($_POST['nombre_completo'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $clave = (string)($_POST['contrasena'] ?? '');
    $claveConfirmar = (string)($_POST['contrasena_confirmar'] ?? '');

    if ($nombreCompleto === '' || $usuario === '' || $clave === '') {
        $error = 'Todos los campos son obligatorios.';
    } elseif (strlen($usuario) < 3) {
        $error = 'El nombre de usuario debe tener al menos 3 caracteres.';
    } elseif (strlen($clave) < 4) {
        $error = 'La contraseña debe tener al menos 4 caracteres.';
    } elseif ($clave !== $claveConfirmar) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        // Ejecutar creación del usuario y aprovisionamiento automático de su BD
        $resultado = crearUsuarioSistema($usuario, $nombreCompleto, $clave, 'Normal');

        if ($resultado['success']) {
            flash('success', $resultado['mensaje']);
            header('Location: ' . url('modules/usuarios/listado.php'));
            exit;
        } else {
            $error = $resultado['error'];
        }
    }
}

$page_title = 'Registrar Nuevo Usuario';
$page_subtitle = 'Usuarios · Aprovisionamiento de BD';
$active_menu = 'usuarios';
include __DIR__ . '/../../includes/layout_top.php';
?>

<div class="page-heading">
    <h1><i class="bi bi-person-plus-fill"></i> Registrar Nuevo Usuario (Base de Datos Propia)</h1>
    <p>Al crear el usuario, el sistema creará automáticamente su base de datos independiente (<strong>luarsoft_db_[usuario]</strong>) con las 13 tablas del ERP/POS listas para operar.</p>
</div>

<div class="card-erp">
    <div class="card-erp-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> <?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="formNuevoUsuario">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="bi bi-card-heading"></i> Nombre Completo</label>
                    <input type="text" name="nombre_completo" class="form-control" placeholder="Ej: Eduardo Viale" value="<?= h($_POST['nombre_completo'] ?? '') ?>" required autofocus>
                    <small class="text-muted">Nombre o razón social de la persona / negocio.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="bi bi-person"></i> Nombre de Usuario</label>
                    <input type="text" name="usuario" id="inputUsuario" class="form-control" placeholder="Ej: eduardo" value="<?= h($_POST['usuario'] ?? '') ?>" required>
                    <small class="text-muted">Se usará para iniciar sesión y nombrar su BD: <code id="previewBd">luarsoft_db_...</code></small>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="bi bi-key"></i> Contraseña</label>
                    <input type="password" name="contrasena" class="form-control" placeholder="Mínimo 4 caracteres" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="bi bi-key-fill"></i> Confirmar Contraseña</label>
                    <input type="password" name="contrasena_confirmar" class="form-control" placeholder="Repite la contraseña" required>
                </div>
            </div>

            <!-- Banner informativo sobre el aprovisionamiento -->
            <div class="alert alert-info mt-4 d-flex align-items-center gap-3">
                <i class="bi bi-database-gear fs-2 text-primary"></i>
                <div>
                    <strong>Aprovisionamiento Automático:</strong>
                    El sistema creará la base de datos exclusiva para este usuario con la estructura de:
                    <em>productos, clientes, ventas, compras, ordenes técnicas, facturas, clientes web y más</em>.
                </div>
            </div>

            <div class="text-end mt-4">
                <button type="submit" name="guardar" class="btn btn-primary px-4">
                    <i class="bi bi-save me-1"></i> Crear Usuario y Aprovisionar BD
                </button>
                <a href="<?= url('modules/usuarios/listado.php') ?>" class="btn btn-secondary ms-2">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('inputUsuario').addEventListener('input', function() {
    const val = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
    document.getElementById('previewBd').innerText = val ? 'luarsoft_db_' + val : 'luarsoft_db_...';
});
</script>

<?php include __DIR__ . '/../../includes/layout_bottom.php'; ?>
