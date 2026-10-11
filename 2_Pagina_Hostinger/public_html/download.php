<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/bootstrap.php';
if (empty($_SESSION['client_id'])) { http_response_code(403); exit('Acceso no autorizado.'); }
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit('Factura no encontrada.'); }
$stmt = db($config)->prepare('SELECT original_name, storage_name, invoice_date, expires_at FROM invoices WHERE id = :id AND client_id = :client_id AND expires_at >= CURDATE() LIMIT 1');
$stmt->execute(['id' => $id, 'client_id' => $_SESSION['client_id']]);
$invoice = $stmt->fetch();
if (!$invoice) { http_response_code(404); exit('Factura no encontrada o vencida.'); }
$path = rtrim($config['private_storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $invoice['storage_name'];
if (!is_file($path)) { http_response_code(404); exit('Archivo no disponible.'); }
// ?dl=1 descarga el archivo; sin él se abre en el navegador.
$modo = isset($_GET['dl']) ? 'attachment' : 'inline';
$nombre = 'Factura ' . $invoice['invoice_date'] . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $modo . '; filename="' . $nombre . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
