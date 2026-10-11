<?php
// Este archivo se ejecuta con PHP CLI desde el Cron Job de Hostinger.
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/bootstrap.php';

$pdo = db($config);
$stmt = $pdo->query('SELECT id, storage_name FROM invoices WHERE expires_at < CURDATE()');
$storage = rtrim($config['private_storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
foreach ($stmt as $invoice) {
    $path = $storage . $invoice['storage_name'];
    if (is_file($path)) {
        unlink($path);
    }
    $delete = $pdo->prepare('DELETE FROM invoices WHERE id = :id');
    $delete->execute(['id' => $invoice['id']]);
}
$pdo->exec('DELETE FROM portal_access_links WHERE expires_at < CURDATE()');
$pdo->exec(
    'DELETE c FROM clients c LEFT JOIN invoices i ON i.client_id = c.id '
    . 'WHERE i.id IS NULL'
);
