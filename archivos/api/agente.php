<?php
// API para el programa de la PC (Monitor de Facturas). Misma clave que api/upload.php.
//   POST JSON {"accion": "..."} con la cabecera X-Upload-Key.
//   ping        → latido del programa (para mostrar "PC conectada" en el celular)
//   clientes    → reemplaza la copia de clientes de un perfil (autocompletado)
//   hay         → cuántas facturas esperan (para avisar antes de tomar el mouse)
//   tomar       → entrega la siguiente factura en cola y la marca "procesando"
//   activas     → facturas que la PC ya tomó y aún no terminó (para retomar tras un reinicio)
//   estado      → actualiza el estado de una factura
$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'error' => 'Método no permitido']);
$clave = (string)($_SERVER['HTTP_X_UPLOAD_KEY'] ?? '');
$hash = (string)($config['upload_key_hash'] ?? '');
if ($clave === '' || $hash === '' || !hash_equals($hash, hash('sha256', $clave))) {
    responder(401, ['ok' => false, 'error' => 'Clave no válida']);
}
$entrada = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($entrada)) responder(400, ['ok' => false, 'error' => 'JSON no válido']);

$pdo = db($config);
app_schema($pdo);

function fila_publica(array $f): array {
    return [
        'id' => (int)$f['id'], 'perfil' => (int)$f['perfil'], 'ruc' => $f['ruc'], 'nombre' => $f['nombre'],
        'direccion' => $f['direccion'], 'telefono' => $f['telefono'], 'email' => $f['email'],
        'cliente_nuevo' => (bool)$f['cliente_nuevo'], 'detalle' => $f['detalle'],
        'total' => (float)$f['total'], 'base' => (float)$f['base'], 'iva' => (float)$f['iva'],
        'con_iva' => (bool)$f['con_iva'], 'origen' => (string)($f['origen'] ?? 'nuevo'),
        'estado' => $f['estado'], 'numero' => $f['numero'], 'autorizacion' => $f['autorizacion'],
        'creada' => $f['created_at'],
    ];
}

switch ((string)($entrada['accion'] ?? '')) {
    case 'ping':
        guardar_ajuste($pdo, 'agente_visto', (string)time());
        guardar_ajuste($pdo, 'agente_info', mb_substr(json_encode([
            'version' => (string)($entrada['version'] ?? ''),
            'estado' => (string)($entrada['estado'] ?? ''),
        ], JSON_UNESCAPED_UNICODE), 0, 500));
        responder(200, ['ok' => true, 'hora' => date('c')]);

    case 'clientes':
        $perfil = (int)($entrada['perfil'] ?? 0);
        $lista = $entrada['clientes'] ?? null;
        if (!isset(FS_PERFILES[$perfil]) || !is_array($lista)) responder(422, ['ok' => false, 'error' => 'Datos incompletos']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM fs_clientes WHERE perfil = :p')->execute(['p' => $perfil]);
            $ins = $pdo->prepare('INSERT INTO fs_clientes (perfil, ruc, codigo, nombre, direccion, telefono, email)
                VALUES (:p, :r, :c, :n, :d, :t, :e) ON DUPLICATE KEY UPDATE codigo=VALUES(codigo), nombre=VALUES(nombre),
                direccion=VALUES(direccion), telefono=VALUES(telefono), email=VALUES(email)');
            $n = 0;
            foreach ($lista as $c) {
                $ruc = preg_replace('/\s+/', '', (string)($c['ruc'] ?? ''));
                if ($ruc === '' || strlen($ruc) > 20) continue;
                $ins->execute([
                    'p' => $perfil, 'r' => $ruc,
                    'c' => mb_substr((string)($c['codigo'] ?? ''), 0, 20),
                    'n' => mb_substr(trim((string)($c['nombre'] ?? '')), 0, 200),
                    'd' => mb_substr(trim((string)($c['direccion'] ?? '')), 0, 255),
                    't' => mb_substr(trim((string)($c['telefono'] ?? '')), 0, 40),
                    'e' => mb_substr(trim((string)($c['email'] ?? '')), 0, 160),
                ]);
                $n++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            responder(500, ['ok' => false, 'error' => 'No se pudieron guardar los clientes']);
        }
        responder(200, ['ok' => true, 'guardados' => $n]);

    case 'hay':
        $n = (int)$pdo->query("SELECT COUNT(*) FROM fs_cola WHERE estado = 'cola'")->fetchColumn();
        $ocupada = (int)$pdo->query("SELECT COUNT(*) FROM fs_cola WHERE estado = 'procesando'")->fetchColumn();
        responder(200, ['ok' => true, 'cola' => $ocupada ? 0 : $n]);

    case 'tomar':
        // Solo una factura a la vez: si hay una "procesando", se espera a que termine.
        $ocupada = (int)$pdo->query("SELECT COUNT(*) FROM fs_cola WHERE estado = 'procesando'")->fetchColumn();
        if ($ocupada) responder(200, ['ok' => true, 'factura' => null, 'motivo' => 'hay una en proceso']);
        for ($i = 0; $i < 3; $i++) {
            $f = $pdo->query("SELECT * FROM fs_cola WHERE estado = 'cola' ORDER BY id LIMIT 1")->fetch();
            if (!$f) responder(200, ['ok' => true, 'factura' => null]);
            $up = $pdo->prepare("UPDATE fs_cola SET estado = 'procesando', tomada_at = NOW(), updated_at = NOW(), mensaje = ''
                WHERE id = :id AND estado = 'cola'");
            $up->execute(['id' => $f['id']]);
            if ($up->rowCount() === 1) {
                $f['estado'] = 'procesando';
                responder(200, ['ok' => true, 'factura' => fila_publica($f)]);
            }
        }
        responder(200, ['ok' => true, 'factura' => null]);

    case 'activas':
        $filas = $pdo->query("SELECT * FROM fs_cola WHERE estado IN ('procesando','registrada','autorizada')
            ORDER BY id")->fetchAll();
        responder(200, ['ok' => true, 'facturas' => array_map('fila_publica', $filas)]);

    case 'estado':
        $id = (int)($entrada['id'] ?? 0);
        $nuevo = (string)($entrada['estado'] ?? '');
        $permitidos = ['procesando', 'registrada', 'autorizada', 'enviada', 'enviada_pend', 'sin_envio', 'error', 'revisar'];
        if ($id <= 0 || !in_array($nuevo, $permitidos, true)) responder(422, ['ok' => false, 'error' => 'Estado no válido']);
        $actual = $pdo->prepare('SELECT estado FROM fs_cola WHERE id = :id');
        $actual->execute(['id' => $id]);
        $estadoActual = $actual->fetchColumn();
        if ($estadoActual === false) responder(404, ['ok' => false, 'error' => 'No existe']);
        if ($estadoActual === 'cancelada') responder(409, ['ok' => false, 'error' => 'Cancelada desde el celular']);
        $campos = ['estado = :e', 'updated_at = NOW()'];
        $valores = ['e' => $nuevo, 'id' => $id];
        foreach (['numero' => 20, 'autorizacion' => 60, 'mensaje' => 400] as $campo => $largo) {
            if (array_key_exists($campo, $entrada)) {
                $campos[] = "$campo = :$campo";
                $valores[$campo] = mb_substr((string)$entrada[$campo], 0, $largo);
            }
        }
        $pdo->prepare('UPDATE fs_cola SET ' . implode(', ', $campos) . ' WHERE id = :id')->execute($valores);
        responder(200, ['ok' => true]);

    default:
        responder(400, ['ok' => false, 'error' => 'Acción desconocida']);
}
