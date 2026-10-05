<?php
/**
 * admin/migrar_tenants.php
 * ------------------------------------------------------------------
 * Script de Migraciones Automáticas para la arquitectura Multi-Tenant
 * (Database-per-User) de LuarSoft / Eros Tecnología.
 *
 * Características:
 * - Control de acceso estricto (solo rol 'Admin').
 * - Procesamiento en lote sobre todas las BDs registadas en usuarios_sistema.
 * - Desactivación del tiempo límite (set_time_limit(0)).
 * - Aislamiento total de errores: la falla en una BD o consulta no detiene
 *   el proceso para los demás inquilinos.
 * - Interfaz de reporte HTML detallada con estadísticas y logs de errores.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant_manager.php';

// 1. Control de Acceso Estricto: Exigir Administrador Master
if (!esAdministrador() || ($_SESSION['rol'] ?? '') !== 'Admin') {
    http_response_code(403);
    die('<div style="font-family:sans-serif; padding:40px; text-align:center;"><h2>Acceso Denegado (403)</h2><p>Se requieren privilegios de Administrador Master para ejecutar migraciones del sistema.</p></div>');
}

$titulo_pagina = "Migración de Tenants · LuarSoft";
$ejecutar = isset($_POST['ejecutar_migracion']) || isset($_GET['autoejecutar']);

// 2. Definición de Consultas de Migración / Actualización de Estructura (Idempotentes)
$consultasMigracion = [
    "CREATE TABLE IF NOT EXISTS `migraciones_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `migracion` VARCHAR(255) NOT NULL,
        `fecha_aplicado` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    "ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `codigo_barras` VARCHAR(50) NULL AFTER `codigo`",
    "ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `tipo_documento` VARCHAR(20) DEFAULT 'DNI' AFTER `documento`",
    "CREATE INDEX IF NOT EXISTS `idx_ventas_fecha` ON `ventas` (`fecha`)"
];

$reporteResultados = [];
$totalTenantsProcesados = 0;
$totalExitosos = 0;
$totalConErrores = 0;

if ($ejecutar) {
    // Evitar desbordamiento por tiempo de ejecución en lotes masivos
    @set_time_limit(0);

    $master = masterConexion();
    $tenants = [];
    $resTenants = mysqli_query($master, "SELECT id, usuario, nombre_completo, nombre_bd_asignada FROM `usuarios_sistema` ORDER BY id ASC");
    if ($resTenants) {
        while ($r = mysqli_fetch_assoc($resTenants)) {
            $tenants[] = $r;
        }
    }

    $totalTenantsProcesados = count($tenants);
    $totalQueries = count($consultasMigracion);

    foreach ($tenants as $t) {
        $nombreBd = trim($t['nombre_bd_asignada']);
        $usuario = $t['usuario'];
        $nombreCompleto = $t['nombre_completo'];

        $itemReporte = [
            'usuario' => $usuario,
            'nombre_completo' => $nombreCompleto,
            'nombre_bd' => $nombreBd,
            'aplicadas' => 0,
            'total_queries' => $totalQueries,
            'estado' => 'Exitoso',
            'errores' => [],
            'conexion_ok' => true
        ];

        // Intento de conexión aislada a la BD tenant
        $connTenant = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $nombreBd);

        if (!$connTenant) {
            $itemReporte['conexion_ok'] = false;
            $itemReporte['estado'] = 'Error Conexión';
            $itemReporte['errores'][] = "No se pudo conectar a la base de datos '{$nombreBd}': " . mysqli_connect_error();
            $totalConErrores++;
            $reporteResultados[] = $itemReporte;
            continue;
        }

        mysqli_set_charset($connTenant, 'utf8mb4');

        // Desactivar temporalmente foreign key checks durante DDL
        @mysqli_query($connTenant, "SET FOREIGN_KEY_CHECKS = 0");

        $queriesExitosas = 0;
        foreach ($consultasMigracion as $index => $sqlQuery) {
            try {
                $qRes = @mysqli_query($connTenant, $sqlQuery);
                if ($qRes) {
                    $queriesExitosas++;
                } else {
                    $itemReporte['errores'][] = "Consulta #" . ($index + 1) . ": " . mysqli_error($connTenant);
                }
            } catch (Throwable $e) {
                $itemReporte['errores'][] = "Consulta #" . ($index + 1) . ": " . $e->getMessage();
            }
        }

        @mysqli_query($connTenant, "SET FOREIGN_KEY_CHECKS = 1");
        mysqli_close($connTenant);

        $itemReporte['aplicadas'] = $queriesExitosas;

        if (!empty($itemReporte['errores'])) {
            $itemReporte['estado'] = ($queriesExitosas > 0) ? 'Parcial con Errores' : 'Falló';
            $totalConErrores++;
        } else {
            $totalExitosos++;
        }

        $reporteResultados[] = $itemReporte;
    }
}

require_once __DIR__ . '/../includes/layout_top.php';
?>

<div class="container-fluid px-4 py-3">

    <!-- Encabezado de Sección -->
    <div class="d-flex align-items-center justify-content-between mb-4 pb-2 border-bottom">
        <div>
            <h1 class="h3 fw-bold mb-1" style="color: var(--n-900);">
                <i class="bi bi-database-fill-gear text-primary me-2"></i>Migrador Automático Multi-Tenant
            </h1>
            <p class="text-muted mb-0" style="font-size:0.9rem;">
                Aplica actualizaciones estructurales (DDL/DML) en lote sobre las bases de datos de todos los inquilinos.
            </p>
        </div>
        <div>
            <form method="POST" action="">
                <button type="submit" name="ejecutar_migracion" value="1" class="btn btn-primary btn-lg shadow-sm" onclick="return confirm('¿Deseas iniciar la migración en lote sobre todas las bases de datos inquilinas?');">
                    <i class="bi bi-play-fill me-1"></i> Ejecutar Migración Masiva
                </button>
            </form>
        </div>
    </div>

    <!-- Panel de Consultas Programadas -->
    <div class="card shadow-sm border-0 mb-4" style="border-radius: 10px;">
        <div class="card-header bg-light py-3 border-0">
            <h6 class="card-title fw-bold mb-0 text-dark">
                <i class="bi bi-code-slash text-secondary me-2"></i>Consultas SQL de Migración Incluidas (<?= count($consultasMigracion) ?>)
            </h6>
        </div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush" style="font-family: monospace; font-size: 0.85rem;">
                <?php foreach ($consultasMigracion as $i => $sql): ?>
                    <li class="list-group-item bg-transparent text-dark py-2">
                        <span class="badge bg-secondary me-2">SQL #<?= $i + 1 ?></span> <?= htmlspecialchars($sql) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <?php if ($ejecutar): ?>
        <!-- Resumen de Métricas de Migración -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 bg-primary text-white shadow-sm p-3" style="border-radius: 10px;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-white-50 text-uppercase fw-semibold" style="font-size:0.75rem;">Tenants Procesados</div>
                            <div class="display-6 fw-bold mb-0"><?= $totalTenantsProcesados ?></div>
                        </div>
                        <i class="bi bi-databases display-4 text-white-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 bg-success text-white shadow-sm p-3" style="border-radius: 10px;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-white-50 text-uppercase fw-semibold" style="font-size:0.75rem;">Migraciones Exitosas</div>
                            <div class="display-6 fw-bold mb-0"><?= $totalExitosos ?></div>
                        </div>
                        <i class="bi bi-check-circle display-4 text-white-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 <?= ($totalConErrores > 0) ? 'bg-danger' : 'bg-secondary' ?> text-white shadow-sm p-3" style="border-radius: 10px;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-white-50 text-uppercase fw-semibold" style="font-size:0.75rem;">Bases con Incidencias</div>
                            <div class="display-6 fw-bold mb-0"><?= $totalConErrores ?></div>
                        </div>
                        <i class="bi bi-exclamation-triangle display-4 text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de Reporte por Tenant -->
        <div class="card shadow-sm border-0 mb-4" style="border-radius: 10px; overflow: hidden;">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title fw-bold mb-0 text-dark">
                    <i class="bi bi-list-check me-2"></i>Reporte Detallado por Base de Datos Inquilina
                </h5>
                <span class="badge bg-info text-dark">Procesamiento Aislado Activo</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Usuario</th>
                            <th>Nombre Completo</th>
                            <th>Base de Datos</th>
                            <th class="text-center">Consultas Aplicadas</th>
                            <th class="text-center">Estado</th>
                            <th>Detalle Técnico / Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reporteResultados as $item): ?>
                            <tr>
                                <td class="ps-3 fw-bold text-primary">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($item['usuario']) ?>
                                </td>
                                <td><?= htmlspecialchars($item['nombre_completo']) ?></td>
                                <td>
                                    <code class="text-dark bg-light px-2 py-1 rounded" style="border: 1px solid #e0e0e0; font-size:0.85rem;">
                                        <?= htmlspecialchars($item['nombre_bd']) ?>
                                    </code>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.85rem;">
                                        <?= $item['aplicadas'] ?> / <?= $item['total_queries'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['estado'] === 'Exitoso'): ?>
                                        <span class="badge bg-success px-2 py-1"><i class="bi bi-check-lg me-1"></i>Exitoso</span>
                                    <?php elseif ($item['estado'] === 'Parcial con Errores'): ?>
                                        <span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-exclamation-triangle me-1"></i>Con Advertencias</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger px-2 py-1"><i class="bi bi-x-circle me-1"></i>Error</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($item['errores'])): ?>
                                        <span class="text-success small"><i class="bi bi-shield-check me-1"></i>Todas las consultas se aplicaron correctamente.</span>
                                    <?php else: ?>
                                        <div class="text-danger small fw-semibold mb-1"><i class="bi bi-bug me-1"></i>Se registraron las siguientes incidencias:</div>
                                        <ul class="mb-0 ps-3 text-danger small">
                                            <?php foreach ($item['errores'] as $err): ?>
                                                <li><?= htmlspecialchars($err) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info d-flex align-items-center shadow-sm p-4" style="border-radius: 10px;">
            <i class="bi bi-info-circle-fill display-5 me-3 text-info"></i>
            <div>
                <h5 class="alert-heading fw-bold mb-1">Listo para Ejecutar</h5>
                <p class="mb-0">Haz clic en el botón <strong>"Ejecutar Migración Masiva"</strong> para actualizar simultáneamente todas las bases de datos tenant activas del ERP/POS.</p>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
require_once __DIR__ . '/../includes/layout_bottom.php';
