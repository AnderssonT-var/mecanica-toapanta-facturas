<?php
require __DIR__ . '/_lib.php';
requiere_admin();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$st = $pdo->prepare('SELECT i.storage_name, i.invoice_date, c.phone FROM invoices i
    JOIN clients c ON c.id = i.client_id WHERE i.id = :id LIMIT 1');
$st->execute(['id' => (int)$id]);
$f = $st->fetch();
$ruta = $f ? rtrim($config['private_storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $f['storage_name'] : '';
if (!$f || !preg_match('/^[a-f0-9]{64}$/', $f['storage_name']) || !is_file($ruta)) {
    http_response_code(404);
    exit('Factura no encontrada.');
}
$nombre = 'Factura ' . $f['phone'] . ' ' . $f['invoice_date'] . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($ruta));
readfile($ruta);
