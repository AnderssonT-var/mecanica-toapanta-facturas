<?php
$config = $config ?? require dirname(__DIR__) . '/config.php';

session_name($config['session_name']);
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

function db(array $config): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['name'], $config['db']['charset']);
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_phone(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', trim($phone)) ?? '';
    if (preg_match('/^09[0-9]{8}$/', $digits)) {
        return '593' . substr($digits, 1);
    }
    return $digits;
}

function is_ecuadorian_mobile(string $phone): bool {
    return (bool)preg_match('/^5939[0-9]{8}$/', $phone);
}

function create_portal_access_link(PDO $pdo, int $clientId, string $expiresAt, array $config): string {
    $portalUrl = rtrim((string)($config['portal_url'] ?? ''), '/');
    if (!filter_var($portalUrl, FILTER_VALIDATE_URL) || !str_starts_with($portalUrl, 'https://')) {
        throw new RuntimeException('Falta configurar portal_url HTTPS en config.php.');
    }
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $insert = $pdo->prepare(
        'INSERT INTO portal_access_links (client_id, token_hash, expires_at) '
        . 'VALUES (:client_id, :token_hash, :expires_at)'
    );
    $insert->execute([
        'client_id' => $clientId,
        'token_hash' => hash('sha256', $token),
        'expires_at' => $expiresAt,
    ]);
    return $portalUrl . '/access.php?token=' . rawurlencode($token);
}
