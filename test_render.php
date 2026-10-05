<?php
session_start();
$_SESSION['usuario'] = 'admin';
$_SESSION['rol'] = 'Admin';
$_SESSION['tenant_db'] = 'luarsoft_db_admin';
$_SESSION['id_usuario'] = 1;

$_SERVER['SCRIPT_NAME'] = '/eros-proyecto/luarsoft/modules/usuarios/listado.php';

ob_start();
require __DIR__ . '/luarsoft/modules/usuarios/listado.php';
$output = ob_get_clean();

$errors = [];
if (preg_match('/(Fatal error|Warning:|Parse error|Notice:|TypeError)/i', $output, $m)) {
    echo "ERROR DETECTADO: " . $m[0] . "\n";
} else {
    echo "RENDER OK! Caracteres generados: " . strlen($output) . "\n";
    if (strpos($output, 'luarsoft_db_eduardo') !== false) {
        echo "Contiene luarsoft_db_eduardo: OK\n";
    }
    if (strpos($output, 'modalStats') !== false) {
        echo "Contiene modalStats: OK\n";
    }
}
