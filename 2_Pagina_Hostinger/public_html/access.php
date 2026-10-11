<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/bootstrap.php';

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
    $_SESSION['login_error'] = 'El enlace de consulta no es válido o ya venció.';
    header('Location: index.php');
    exit;
}

$stmt = db($config)->prepare(
    'SELECT c.id, c.phone, l.id AS link_id '
    . 'FROM portal_access_links l '
    . 'INNER JOIN clients c ON c.id = l.client_id '
    . 'WHERE l.token_hash = :token_hash AND l.expires_at >= CURDATE() LIMIT 1'
);
$stmt->execute(['token_hash' => hash('sha256', $token)]);
$client = $stmt->fetch();
if (!$client) {
    $_SESSION['login_error'] = 'El enlace de consulta venció o no es válido.';
    header('Location: index.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['client_id'] = (int)$client['id'];
$_SESSION['phone'] = $client['phone'];
$used = db($config)->prepare('UPDATE portal_access_links SET last_used_at = NOW() WHERE id = :id');
$used->execute(['id' => $client['link_id']]);
header('Location: history.php');
exit;
