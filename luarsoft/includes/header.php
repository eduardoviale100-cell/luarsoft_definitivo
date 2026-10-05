<?php
/**
 * includes/header.php
 * ------------------------------------------------------------------
 * Barra superior (topbar). Usa $page_title y $page_subtitle si están
 * definidas en la página que lo incluye.
 */
$page_title = $page_title ?? 'LuarSoft';
$page_subtitle = $page_subtitle ?? '';
$nombre_usuario = usuarioActual();
$inicial_usuario = strtoupper(substr($nombre_usuario, 0, 1));
$rol_usuario = $_SESSION['rol'] ?? 'Administrador';

$foto_usuario = null;
if (!empty($_SESSION['id_usuario']) && isset($conexion)) {
    $stmtFotoTopbar = mysqli_prepare($conexion, "SELECT foto FROM usuarios WHERE id_usuario = ? LIMIT 1");
    mysqli_stmt_bind_param($stmtFotoTopbar, "i", $_SESSION['id_usuario']);
    mysqli_stmt_execute($stmtFotoTopbar);
    $foto_usuario = mysqli_stmt_get_result($stmtFotoTopbar)->fetch_assoc()['foto'] ?? null;
}

require_once __DIR__ . '/tenant_manager.php';

$bdActiva = $_SESSION['tenant_db'] ?? DB_NAME;
$listaTenants = [];
if (esAdministrador()) {
    $listaTenants = listarUsuariosSistema();
}
?>
<header class="topbar">
    <div class="topbar-left">
        <button class="topbar-toggle" id="toggleSidebar" type="button" title="Mostrar/ocultar menú"><i class="bi bi-list icon-lg"></i></button>
        <div>
            <div class="topbar-title"><?= h($page_title) ?></div>
            <?php if ($page_subtitle): ?>
                <div class="topbar-breadcrumb"><?= h($page_subtitle) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="topbar-right">
        <?php if (esAdministrador() && !empty($listaTenants)): ?>
            <div class="dropdown me-1">
                <button class="btn btn-sm <?= ($bdActiva !== 'luarsoft_db_admin' && $bdActiva !== 'luarsoft') ? 'btn-warning text-dark fw-bold' : 'btn-outline-success' ?> dropdown-toggle d-flex align-items-center gap-1" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Cambiar base de datos activa (Modo Soporte)">
                    <i class="bi bi-database-fill"></i>
                    <span class="d-none d-md-inline"><?= h($bdActiva) ?></span>
                    <?php if ($bdActiva !== 'luarsoft_db_admin' && $bdActiva !== 'luarsoft'): ?>
                        <span class="badge bg-danger ms-1" style="font-size:0.65rem;">SOPORTE</span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><h6 class="dropdown-header text-uppercase fw-bold"><i class="bi bi-layers me-1"></i> Modo Soporte / Bases de Datos</h6></li>
                    <?php foreach ($listaTenants as $t): ?>
                        <?php $esSel = ($bdActiva === $t['nombre_bd_asignada']); ?>
                        <li>
                            <a class="dropdown-item d-flex justify-content-between align-items-center py-2 <?= $esSel ? 'active' : '' ?>" href="<?= url('modules/usuarios/cambiar_bd.php?bd=' . urlencode($t['nombre_bd_asignada'])) ?>">
                                <div>
                                    <div class="fw-bold"><i class="bi bi-database me-1"></i><?= h($t['nombre_bd_asignada']) ?></div>
                                    <small class="<?= $esSel ? 'text-white-50' : 'text-muted' ?>">Usuario: <?= h($t['usuario']) ?> (<?= h($t['rol']) ?>)</small>
                                </div>
                                <?php if ($esSel): ?>
                                    <span class="badge bg-light text-dark ms-2"><i class="bi bi-check-lg"></i> Activa</span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <div class="d-none d-md-flex align-items-center gap-1 me-2 px-2 py-1 rounded bg-light border text-muted small" title="Tu base de datos dedicada">
                <i class="bi bi-database text-success"></i>
                <code class="text-success fw-bold"><?= h($bdActiva) ?></code>
            </div>
        <?php endif; ?>

        <div class="status-pill">
            <span class="status-dot"></span> <span class="status-pill-text">Servidor en línea</span>
        </div>

        <?php if (tienePermiso('pos')): ?>
        <button class="icon-btn" title="Accesos rápidos: Punto de Venta" onclick="window.location.href='<?= url('modules/ventas/pos.php') ?>'"><i class="bi bi-lightning-charge-fill"></i></button>
        <?php endif; ?>

        <button class="theme-toggle-btn" id="themeToggleBtn" type="button" title="Cambiar a modo oscuro">
            <i class="bi bi-moon-stars-fill"></i>
            <i class="bi bi-sun-fill"></i>
        </button>

        <button class="icon-btn" title="Notificaciones">
            <i class="bi bi-bell"></i><span class="badge-dot"></span>
        </button>

        <div class="user-chip" title="Sesión de <?= h($nombre_usuario) ?>">
            <?php if (!empty($foto_usuario)): ?>
                <img class="user-avatar" src="<?= url('uploads/usuarios/' . $foto_usuario) ?>" alt="<?= h($nombre_usuario) ?>" style="object-fit:cover;">
            <?php else: ?>
                <div class="user-avatar"><?= h($inicial_usuario) ?></div>
            <?php endif; ?>
            <div>
                <div class="user-name"><?= h($nombre_usuario) ?></div>
                <div class="user-role"><?= h($rol_usuario) ?></div>
            </div>
        </div>
    </div>
</header>
