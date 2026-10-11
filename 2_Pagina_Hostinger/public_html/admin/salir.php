<?php
require __DIR__ . '/_lib.php';
unset($_SESSION['admin_desde'], $_SESSION['admin_uso'], $_SESSION['admin_flash']);
session_regenerate_id(true);
header('Location: index.php');
