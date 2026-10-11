<?php
$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}
$providedKey = (string)($_SERVER['HTTP_X_UPLOAD_KEY'] ?? '');
$expectedHash = (string)($config['upload_key_hash'] ?? '');
if (!$providedKey || !$expectedHash || !hash_equals($expectedHash, hash('sha256', $providedKey))) {
    http_response_code(401);
    exit('Clave de subida no válida.');
}

$phone = normalize_phone((string)($_POST['phone'] ?? ''));
$date = (string)($_POST['invoice_date'] ?? date('Y-m-d'));
if (!is_ecuadorian_mobile($phone)) {
    http_response_code(422);
    exit('Teléfono no válido.');
}
$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
    http_response_code(422);
    exit('Fecha no válida.');
}
$file = $_FILES['invoice'] ?? null;
if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) {
    http_response_code(422);
    exit('Debes enviar un PDF de máximo 10 MB.');
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
if ($finfo->file($file['tmp_name']) !== 'application/pdf') {
    http_response_code(422);
    exit('El archivo debe ser PDF.');
}

$storageName = bin2hex(random_bytes(32));
$storage = rtrim($config['private_storage'], DIRECTORY_SEPARATOR);
if (!is_dir($storage) && !mkdir($storage, 0700, true)) {
    http_response_code(500);
    exit('No se pudo preparar el almacenamiento privado.');
}
$destination = $storage . DIRECTORY_SEPARATOR . $storageName;
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    exit('No se pudo guardar la factura.');
}
$originalName = basename((string)$file['name']);
$retentionDays = (int)($config['retention_days'] ?? 30);
if ($retentionDays < 1 || $retentionDays > 365) {
    $retentionDays = 30;
}
$expiresAt = $dateObject->modify('+' . $retentionDays . ' days')->format('Y-m-d');
$pdo = db($config);
try {
    $pdo->beginTransaction();
    // LAST_INSERT_ID permite obtener el mismo id tanto si el teléfono es nuevo como si ya existe.
    $upsertClient = $pdo->prepare(
        'INSERT INTO clients (phone) VALUES (:phone) '
        . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $upsertClient->execute(['phone' => $phone]);
    $clientId = (int)$pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT INTO invoices (client_id, original_name, storage_name, invoice_date, expires_at) VALUES (:client_id, :original_name, :storage_name, :invoice_date, :expires_at)');
    $insert->execute([
        'client_id' => $clientId,
        'original_name' => $originalName,
        'storage_name' => $storageName,
        'invoice_date' => $date,
        'expires_at' => $expiresAt,
    ]);
    $invoiceId = (int)$pdo->lastInsertId();
    $portalUrl = create_portal_access_link($pdo, $clientId, $expiresAt, $config);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($destination);
    http_response_code(500);
    exit('No se pudo registrar la factura.');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'ok' => true,
    'invoice_id' => $invoiceId,
    'expires_at' => $expiresAt,
    'portal_url' => $portalUrl,
], JSON_UNESCAPED_UNICODE);
