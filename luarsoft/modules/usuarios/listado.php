<?php
/**
 * modules/usuarios/listado.php
 * ------------------------------------------------------------------
 * Gestión Central de Usuarios y Bases de Datos (Multi-Tenant).
 * Muestra las cuentas de luarsoft.usuarios_sistema, su base de datos
 * dedicada y proporciona la barra de herramientas de 7 acciones avanzadas.
 */
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/funciones.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tenant_manager.php';

// Solo el Administrador Master puede ver esta pantalla
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
$bdActiva = $_SESSION['tenant_db'] ?? DB_NAME;

$page_title = 'Gestión de Usuarios y Bases de Datos';
$page_subtitle = 'Sistema · Multi-Tenant (Database-per-User)';
$active_menu = 'usuarios';
include __DIR__ . '/../../includes/layout_top.php';
?>

<div class="page-heading d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1><i class="bi bi-diagram-3-fill text-success"></i> Gestión de Usuarios y Bases de Datos</h1>
        <p>Administra las cuentas de usuario y sus bases de datos dedicadas independientes (<strong>Database-per-User</strong>).</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('modules/usuarios/nuevo.php') ?>" class="btn btn-primary no-print shadow-sm">
            <i class="bi bi-plus-lg me-1"></i> Registrar Nuevo Usuario
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
                    <span class="badge bg-warning text-dark"><i class="bi bi-shield-exclamation me-1"></i> Modo Soporte Activo</span>
                <?php else: ?>
                    <span class="badge bg-light text-muted border"><i class="bi bi-check-circle me-1"></i> BD Principal Admin</span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <?php if ($bdActiva !== 'luarsoft_db_admin' && $bdActiva !== 'luarsoft'): ?>
                <a href="<?= url('modules/usuarios/cambiar_bd.php?bd=luarsoft_db_admin') ?>" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-arrow-return-left me-1"></i> Volver a mi BD (Admin)
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="table-erp-wrap shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Usuario</th>
                    <th>Nombre Completo</th>
                    <th>Base de Datos Asignada</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Fecha Registro</th>
                    <th class="no-print text-center" style="min-width: 320px;">Acciones Multi-Tenant</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($usuarios)): ?>
                <?php foreach ($usuarios as $fila): ?>
                    <?php 
                        $esBdActual = ($bdActiva === $fila['nombre_bd_asignada']);
                        $esAdmin = ($fila['rol'] === 'Admin' || $fila['usuario'] === 'admin');
                        $estaActivo = ($fila['estado'] === 'activo');
                    ?>
                    <tr class="<?= $esBdActual ? 'table-success bg-opacity-10' : '' ?>">
                        <td><strong>#<?= h($fila['id']) ?></strong></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar-sm rounded-circle d-inline-flex align-items-center justify-content-center bg-light border" style="width:34px; height:34px;">
                                    <i class="bi <?= $esAdmin ? 'bi-shield-shaded text-primary' : 'bi-person-circle text-success' ?>"></i>
                                </span>
                                <div>
                                    <span class="fw-bold d-block"><?= h($fila['usuario']) ?></span>
                                    <?php if ($fila['usuario'] === ($_SESSION['usuario'] ?? '')): ?>
                                        <span class="badge bg-secondary" style="font-size:0.7rem;">Tu sesión</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= h($fila['nombre_completo']) ?></td>
                        <td>
                            <code class="px-2 py-1 bg-light rounded text-success fw-bold" style="border:1px solid #cce5d4; font-size:0.85rem;">
                                <i class="bi bi-database me-1"></i><?= h($fila['nombre_bd_asignada']) ?>
                            </code>
                        </td>
                        <td>
                            <span class="badge <?= $esAdmin ? 'bg-primary' : 'bg-info text-dark' ?>">
                                <i class="bi <?= $esAdmin ? 'bi-shield-lock-fill' : 'bi-person-check-fill' ?> me-1"></i>
                                <?= h($fila['rol']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $estaActivo ? 'bg-success' : 'bg-danger' ?>">
                                <i class="bi <?= $estaActivo ? 'bi-check-circle-fill' : 'bi-slash-circle-fill' ?> me-1"></i>
                                <?= h(ucfirst($fila['estado'])) ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?= !empty($fila['fecha_registro']) ? h(date('d/m/Y H:i', strtotime($fila['fecha_registro']))) : '—' ?></small></td>
                        <td class="no-print text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                
                                <!-- 1. MODO SOPORTE: ENTRAR A BD -->
                                <?php if ($esBdActual): ?>
                                    <button class="btn btn-success" disabled title="Ya estás conectado a esta base de datos">
                                        <i class="bi bi-check2-circle"></i> Conectado
                                    </button>
                                <?php else: ?>
                                    <a href="<?= url('modules/usuarios/cambiar_bd.php?bd=' . urlencode($fila['nombre_bd_asignada'])) ?>" 
                                       class="btn btn-outline-success" 
                                       title="Entrar a esta base de datos en Modo Soporte">
                                        <i class="bi bi-box-arrow-in-right"></i> Entrar
                                    </a>
                                <?php endif; ?>

                                <!-- 2. STATS BD -->
                                <button type="button" class="btn btn-outline-secondary" 
                                        onclick="abrirModalStats('<?= h($fila['nombre_bd_asignada']) ?>', '<?= h($fila['usuario']) ?>')" 
                                        title="Ver estadísticas y tamaño de la base de datos">
                                    <i class="bi bi-bar-chart-line"></i> Stats
                                </button>

                                <!-- 3. RESETEAR CONTRASEÑA -->
                                <button type="button" class="btn btn-outline-warning text-dark" 
                                        onclick="abrirModalClave(<?= (int)$fila['id'] ?>, '<?= h($fila['usuario']) ?>')" 
                                        title="Resetear contraseña">
                                    <i class="bi bi-key"></i> Clave
                                </button>

                                <!-- 4. SUSPENDER / ACTIVAR -->
                                <?php if (!$esAdmin): ?>
                                    <a href="<?= url('modules/usuarios/acciones.php?accion=toggle_estado&id_usuario=' . (int)$fila['id']) ?>" 
                                       class="btn <?= $estaActivo ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                       title="<?= $estaActivo ? 'Suspender acceso a este usuario' : 'Activar acceso a este usuario' ?>"
                                       onclick="return confirm('¿Seguro que deseas <?= $estaActivo ? 'SUSPENDER' : 'ACTIVAR' ?> al usuario «<?= h($fila['usuario']) ?>»?');">
                                        <i class="bi <?= $estaActivo ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary" disabled title="El administrador principal no puede ser suspendido">
                                        <i class="bi bi-shield-check"></i>
                                    </button>
                                <?php endif; ?>

                                <!-- 5. DESCARGAR BACKUP SQL -->
                                <a href="<?= url('modules/usuarios/acciones.php?accion=backup&bd=' . urlencode($fila['nombre_bd_asignada'])) ?>" 
                                   class="btn btn-outline-info" 
                                   title="Descargar respaldo SQL (.sql) de esta base de datos">
                                    <i class="bi bi-download"></i> Backup
                                </a>

                                <!-- DROPDOWN PARA ACCIONES DE RIESGO (Reset BD y Eliminar) -->
                                <?php if (!$esAdmin): ?>
                                    <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" title="Opciones avanzadas">
                                        <span class="visually-hidden">Más opciones</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <!-- 6. FACTORY RESET -->
                                        <li>
                                            <a class="dropdown-item text-warning" href="#" 
                                               onclick="abrirModalReset('<?= h($fila['nombre_bd_asignada']) ?>', '<?= h($fila['usuario']) ?>'); return false;">
                                                <i class="bi bi-arrow-counterclockwise me-2"></i> Re-inicializar BD (Factory Reset)
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <!-- 7. ELIMINAR USUARIO -->
                                        <li>
                                            <a class="dropdown-item text-danger" href="#" 
                                               onclick="abrirModalEliminar(<?= (int)$fila['id'] ?>, '<?= h($fila['usuario']) ?>', '<?= h($fila['nombre_bd_asignada']) ?>'); return false;">
                                                <i class="bi bi-trash3 me-2"></i> Eliminar Usuario...
                                            </a>
                                        </li>
                                    </ul>
                                <?php endif; ?>

                            </div>
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

<!-- ========================================================================= -->
<!-- MODALES DE GESTIÓN AVANZADA                                               -->
<!-- ========================================================================= -->

<!-- MODAL 1: STATS DE LA BD -->
<div class="modal fade" id="modalStats" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bi bi-bar-chart-line text-primary me-2"></i> Estadísticas de Base de Datos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="statsCargando" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Consultando métricas en el servidor MySQL...</p>
                </div>
                <div id="statsContenido" style="display:none;">
                    <div class="text-center mb-3">
                        <div class="badge bg-success-subtle text-success fs-6 px-3 py-2 border border-success-subtle" id="statsNombreBd"></div>
                        <div class="small text-muted mt-1">Usuario asignado: <strong id="statsUsuario"></strong></div>
                    </div>
                    <div class="row g-2 text-center">
                        <div class="col-6">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Tamaño en Disco</div>
                                <div class="fs-4 fw-bold text-primary" id="statsMb">0.0 MB</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Total de Tablas</div>
                                <div class="fs-4 fw-bold text-dark" id="statsTablas">0</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Productos Registrados</div>
                                <div class="fs-4 fw-bold text-success" id="statsProd">0</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Clientes Registrados</div>
                                <div class="fs-4 fw-bold text-info" id="statsCli">0</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Ventas Realizadas</div>
                                <div class="fs-4 fw-bold text-warning" id="statsVentas">0</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded border">
                                <div class="text-muted small">Órdenes de Taller</div>
                                <div class="fs-4 fw-bold text-danger" id="statsOrd">0</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 2: RESETEAR CONTRASEÑA -->
<div class="modal fade" id="modalClave" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= url('modules/usuarios/acciones.php') ?>" class="modal-content">
            <input type="hidden" name="accion" value="reset_clave">
            <input type="hidden" name="id_usuario" id="claveIdUsuario" value="">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bi bi-key-fill text-warning me-2"></i> Resetear Contraseña</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p>Establece una nueva contraseña para el usuario <strong id="claveNombreUsuario"></strong>:</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Nueva Contraseña</label>
                    <input type="password" name="nueva_clave" class="form-control" placeholder="Mínimo 4 caracteres" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Confirmar Contraseña</label>
                    <input type="password" name="confirmar_clave" class="form-control" placeholder="Repite la contraseña" required>
                </div>
                <div class="alert alert-info small mb-0">
                    <i class="bi bi-shield-check me-1"></i> La contraseña será encriptada automáticamente con <strong>Bcrypt</strong> y sincronizada tanto en el panel central como en su base de datos local.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning text-dark"><i class="bi bi-check2 me-1"></i> Guardar Nueva Contraseña</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: FACTORY RESET (RE-INICIALIZAR BD) -->
<div class="modal fade" id="modalReset" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= url('modules/usuarios/acciones.php') ?>" class="modal-content border-warning">
            <input type="hidden" name="accion" value="factory_reset">
            <input type="hidden" name="nombre_bd" id="resetNombreBd" value="">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i> Re-inicializar Base de Datos (Factory Reset)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="text-danger fw-bold">
                    ⚠️ ADVERTENCIA: Esta acción vaciará por completo la base de datos <code id="resetBdTexto"></code> y restaurará las 13 tablas a su estado inicial de fábrica.
                </p>
                <p>Todos los registros creados por el usuario <strong id="resetUsuarioTexto"></strong> (ventas, clientes nuevos, productos propios) serán reemplazados por la plantilla base.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Para confirmar, escribe exactamente la palabra <code class="text-danger">RESETEAR</code>:</label>
                    <input type="text" name="confirmar_reset" class="form-control" placeholder="Escribe RESETEAR" required autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning text-dark"><i class="bi bi-arrow-counterclockwise me-1"></i> Confirmar y Restaurar de Fábrica</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 4: ELIMINAR USUARIO -->
<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= url('modules/usuarios/acciones.php') ?>" class="modal-content border-danger">
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id_usuario" id="delIdUsuario" value="">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash3-fill me-2"></i> Eliminar Usuario del Sistema</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p>¿Estás seguro de que deseas eliminar permanentemente al usuario <strong id="delNombreUsuario" class="text-danger"></strong>?</p>
                <p class="small text-muted">Este usuario ya no podrá iniciar sesión en LuarSoft ni en la web pública.</p>
                
                <div class="p-3 bg-light rounded border border-danger-subtle">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="borrar_bd_fisica" value="1" id="checkBorrarBd">
                        <label class="form-check-label fw-bold text-danger" for="checkBorrarBd">
                            <i class="bi bi-database-x me-1"></i> Eliminar también la base de datos MySQL física (<code id="delBdTexto"></code>)
                        </label>
                    </div>
                    <small class="text-muted d-block mt-1">Si dejas esta casilla desmarcada, la base de datos se conservará intacta como respaldo inactivo.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i> Eliminar Definitivamente</button>
            </div>
        </form>
    </div>
</div>

<script>
// Manejador del Modal de Estadísticas
function abrirModalStats(nombreBd, usuario) {
    const modalEl = document.getElementById('modalStats');
    const modal = new bootstrap.Modal(modalEl);
    
    document.getElementById('statsCargando').style.display = 'block';
    document.getElementById('statsContenido').style.display = 'none';
    modal.show();

    fetch('<?= url("modules/usuarios/acciones.php") ?>?accion=stats&bd=' + encodeURIComponent(nombreBd))
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                const d = res.data;
                document.getElementById('statsNombreBd').innerText = d.nombre_bd;
                document.getElementById('statsUsuario').innerText = usuario;
                document.getElementById('statsMb').innerText = d.tamano_mb.toFixed(2) + ' MB';
                document.getElementById('statsTablas').innerText = d.tablas;
                document.getElementById('statsProd').innerText = d.productos;
                document.getElementById('statsCli').innerText = d.clientes;
                document.getElementById('statsVentas').innerText = d.ventas;
                document.getElementById('statsOrd').innerText = d.ordenes;

                document.getElementById('statsCargando').style.display = 'none';
                document.getElementById('statsContenido').style.display = 'block';
            } else {
                alert('Error al obtener estadísticas: ' + (res.error || 'Desconocido'));
                modal.hide();
            }
        })
        .catch(err => {
            alert('Error en la comunicación con el servidor.');
            modal.hide();
        });
}

// Manejador del Modal de Cambio de Clave
function abrirModalClave(id, usuario) {
    document.getElementById('claveIdUsuario').value = id;
    document.getElementById('claveNombreUsuario').innerText = '«' + usuario + '»';
    new bootstrap.Modal(document.getElementById('modalClave')).show();
}

// Manejador del Modal de Factory Reset
function abrirModalReset(nombreBd, usuario) {
    document.getElementById('resetNombreBd').value = nombreBd;
    document.getElementById('resetBdTexto').innerText = nombreBd;
    document.getElementById('resetUsuarioTexto').innerText = usuario;
    new bootstrap.Modal(document.getElementById('modalReset')).show();
}

// Manejador del Modal de Eliminación
function abrirModalEliminar(id, usuario, nombreBd) {
    document.getElementById('delIdUsuario').value = id;
    document.getElementById('delNombreUsuario').innerText = usuario;
    document.getElementById('delBdTexto').innerText = nombreBd;
    document.getElementById('checkBorrarBd').checked = false;
    new bootstrap.Modal(document.getElementById('modalEliminar')).show();
}
</script>

<?php include __DIR__ . '/../../includes/layout_bottom.php'; ?>
