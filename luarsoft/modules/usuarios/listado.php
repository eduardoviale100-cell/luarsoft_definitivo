<?php
/**
 * modules/usuarios/listado.php
 * ------------------------------------------------------------------
 * Gestión Central de Usuarios y Bases de Datos (Multi-Tenant).
 * Muestra las cuentas de luarsoft.usuarios_sistema, su base de datos
 * dedicada y permite al Administrador cambiar a cualquier BD en modo soporte.
 */
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tenant_manager.php';

// Solo el Administrador puede ver esta pantalla
if (!esAdministrador()) {
    flash('error', 'Acceso denegado: este módulo es exclusivo del Administrador.');
    header('Location: ' . url('index.php'));
    exit;
}

$master = masterConexion();
$res = mysqli_query($master, "SELECT * FROM `usuarios_sistema` ORDER BY `id` ASC");
$usuarios = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $usuarios[] = $row;
    }
}
$totalUsuarios = count($usuarios);
$bdActiva = $_SESSION['tenant_db'] ?? DB_NAME;

$page_title = 'Gestión de Usuarios y Bases de Datos';
$page_subtitle = 'Sistema · Multi-Tenant (Database-per-User)';
$active_menu = 'usuarios';
include __DIR__ . '/../../includes/layout_top.php';
?>

<div class="page-heading d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1><i class="bi bi-diagram-3-fill"></i> Usuarios y Bases de Datos del Sistema</h1>
        <p>Administra las cuentas de usuario y sus bases de datos dedicadas (<strong>Database-per-User</strong>).</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('modules/usuarios/nuevo.php') ?>" class="btn btn-primary no-print">
            <i class="bi bi-plus-lg"></i> Registrar Nuevo Usuario
        </a>
    </div>
</div>

<!-- Tarjeta de Estado del Modo Soporte -->
<div class="card-erp mb-4 shadow-sm" style="border-left: 4px solid var(--accent-color, #1FA35C);">
    <div class="card-erp-body d-flex justify-content-between align-items-center flex-wrap gap-3 py-3">
        <div>
            <div class="text-muted small text-uppercase fw-bold">Base de Datos Actualmente Conectada:</div>
            <div class="d-flex align-items-center gap-2 mt-1">
                <span class="badge bg-success" style="font-size:0.95rem; font-family:monospace; padding:6px 12px;">
                    <i class="bi bi-database-check me-1"></i> <?= h($bdActiva) ?>
                </span>
                <?php if ($bdActiva !== 'luarsoft_db_admin' && $bdActiva !== 'luarsoft'): ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-shield-exclamation"></i> Modo Soporte Activo</span>
                <?php else: ?>
                    <span class="badge bg-light text-muted border"><i class="bi bi-check-circle"></i> BD Principal Admin</span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <?php if ($bdActiva !== 'luarsoft_db_admin' && $bdActiva !== 'luarsoft'): ?>
                <a href="<?= url('modules/usuarios/cambiar_bd.php?bd=luarsoft_db_admin') ?>" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-arrow-return-left"></i> Volver a mi BD (Admin)
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="table-erp-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Nombre Completo</th>
                    <th>Base de Datos Asignada</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Fecha Registro</th>
                    <th class="no-print text-center">Acciones / Modo Soporte</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($usuarios)): ?>
                <?php foreach ($usuarios as $fila): ?>
                    <?php 
                        $esBdActual = ($bdActiva === $fila['nombre_bd_asignada']);
                        $esAdmin = ($fila['rol'] === 'Admin');
                    ?>
                    <tr class="<?= $esBdActual ? 'table-success bg-opacity-10' : '' ?>">
                        <td><strong>#<?= h($fila['id']) ?></strong></td>
                        <td>
                            <span class="fw-bold"><?= h($fila['usuario']) ?></span>
                            <?php if ($fila['usuario'] === ($_SESSION['usuario'] ?? '')): ?>
                                <span class="badge bg-secondary ms-1">Tú</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($fila['nombre_completo']) ?></td>
                        <td>
                            <code class="px-2 py-1 bg-light rounded text-success fw-bold" style="border:1px solid #cce5d4;">
                                <i class="bi bi-database me-1"></i><?= h($fila['nombre_bd_asignada']) ?>
                            </code>
                        </td>
                        <td>
                            <span class="badge <?= $esAdmin ? 'bg-primary' : 'bg-info text-dark' ?>">
                                <i class="bi <?= $esAdmin ? 'bi-shield-lock-fill' : 'bi-person-check-fill' ?>"></i>
                                <?= h($fila['rol']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $fila['estado'] === 'activo' ? 'bg-success' : 'bg-danger' ?>">
                                <?= h(ucfirst($fila['estado'])) ?>
                            </span>
                        </td>
                        <td><?= !empty($fila['fecha_registro']) ? h(date('d/m/Y H:i', strtotime($fila['fecha_registro']))) : '—' ?></td>
                        <td class="no-print text-center">
                            <?php if ($esBdActual): ?>
                                <button class="btn btn-sm btn-outline-success disabled" title="Base de datos actualmente activa">
                                    <i class="bi bi-check-circle-fill"></i> Conectado
                                </button>
                            <?php else: ?>
                                <a href="<?= url('modules/usuarios/cambiar_bd.php?bd=' . urlencode($fila['nombre_bd_asignada'])) ?>" 
                                   class="btn btn-sm btn-outline-primary" 
                                   title="Conectarse a esta base de datos en modo soporte">
                                    <i class="bi bi-box-arrow-in-right"></i> Entrar a BD
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No hay usuarios registrados en el sistema central.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout_bottom.php'; ?>
