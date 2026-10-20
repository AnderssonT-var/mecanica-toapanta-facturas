<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
fx_config();
verificar_csrf();
$_SESSION = [];
session_destroy();
redirigir('login.php');
