<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
fx_config();
redirigir(admin_actual() ? 'panel.php' : 'login.php');
