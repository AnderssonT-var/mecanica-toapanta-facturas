<?php
// API JSON de la app de Android (Facmit para celular). Misma cuenta, permisos y reglas que la app web;
// en lugar de cookies usa un token por equipo que se envía en la cabecera X-Facmit-Token.
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/web.php';

const API_TOKEN_DIAS = 90; // el token vence tras 90 días sin usar la app
const API_VERSION = 1;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function api_salida(array $d, int $codigo = 200): void {
  http_response_code($codigo);
  echo json_encode(['ok' => $codigo < 400] + $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
  exit;
}
function api_error(string $msg, int $codigo = 400, string $clave = ''): void { api_salida(['error' => $msg] + ($clave ? ['codigo' => $clave] : []), $codigo); }

function api_esquema(): void {
  static $listo = false;
  if ($listo) return;
  if (fx_config()['db']['driver'] === 'sqlite') {
    db()->exec('CREATE TABLE IF NOT EXISTS nube_app_tokens (hash TEXT PRIMARY KEY, usuario_id INTEGER NOT NULL, perfil TEXT NOT NULL DEFAULT \'\', equipo TEXT NOT NULL DEFAULT \'\', creado TEXT NOT NULL, ultimo_uso TEXT NOT NULL)');
  } else {
    db()->exec("CREATE TABLE IF NOT EXISTS nube_app_tokens (hash CHAR(64) NOT NULL PRIMARY KEY, usuario_id INT UNSIGNED NOT NULL, perfil CHAR(13) NOT NULL DEFAULT '', equipo VARCHAR(80) NOT NULL DEFAULT '', creado DATETIME NOT NULL, ultimo_uso DATETIME NOT NULL, KEY (usuario_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  $listo = true;
}

function api_token_recibido(): string {
  $t = (string)($_SERVER['HTTP_X_FACMIT_TOKEN'] ?? '');
  if ($t === '' && preg_match('/^Bearer\s+(\S+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) $t = $m[1];
  return preg_match('/^[A-Za-z0-9_-]{40,64}$/', $t) ? $t : '';
}

// Contexto del token: el mismo que arma la app web para una sesión. Responde 401 si no sirve.
function api_contexto(): array {
  api_esquema();
  $t = api_token_recibido();
  if ($t === '') api_error('Inicia sesión.', 401, 'SESION');
  $h = hash('sha256', $t);
  $q = db()->prepare('SELECT * FROM nube_app_tokens WHERE hash = ?');
  $q->execute([$h]);
  $tok = $q->fetch();
  if (!$tok || strtotime((string)$tok['ultimo_uso']) < time() - API_TOKEN_DIAS * 86400) {
    if ($tok) db()->prepare('DELETE FROM nube_app_tokens WHERE hash = ?')->execute([$h]);
    api_error('Tu sesión venció. Vuelve a iniciar sesión.', 401, 'SESION');
  }
  $ctx = web_contexto_usuario((int)$tok['usuario_id'], (string)$tok['perfil']);
  if (!$ctx) { db()->prepare('DELETE FROM nube_app_tokens WHERE hash = ?')->execute([$h]); api_error('Tu usuario ya no está activo.', 401, 'SESION'); }
  if (strtotime((string)$tok['ultimo_uso']) < time() - 300) db()->prepare('UPDATE nube_app_tokens SET ultimo_uso = ? WHERE hash = ?')->execute([ahora(), $h]);
  $ctx['_token'] = $h;
  return $ctx;
}

function api_entrada(): array {
  $crudo = (string)file_get_contents('php://input');
  if ($crudo === '') return $_POST;
  if (strlen($crudo) > 1_000_000) api_error('La solicitud es demasiado grande.', 413);
  $j = json_decode($crudo, true);
  if (!is_array($j)) api_error('Datos no válidos.');
  return $j;
}

function api_yo(array $ctx): array {
  $emp = $ctx['empresa'];
  $perfiles = [];
  foreach ($ctx['perfiles'] as $p) { $e = $p === $ctx['perfil'] ? $emp : (nube_perfil($ctx['lic'], $p) ?? []); $perfiles[] = ['ruc' => $p, 'nombre' => (string)($e['nombreComercial'] ?? '') ?: (string)($e['razonSocial'] ?? $p)]; }
  $u = $ctx['usuario'];
  return [
    'usuario' => ['id' => (int)$u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'] ?: $u['usuario'], 'rol' => $u['rol']],
    'permisos' => ['ver' => web_puede($ctx, 'ver'), 'editar' => web_puede($ctx, 'editar'), 'facturar' => web_puede($ctx, 'facturar'), 'inventario' => web_inv_activo($ctx)],
    'bloqueo' => $ctx['bloqueo'],
    'perfil' => $ctx['perfil'], 'perfiles' => $perfiles,
    'empresa' => ['ruc' => $emp['ruc'] ?? $ctx['perfil'], 'razonSocial' => $emp['razonSocial'] ?? '', 'nombreComercial' => $emp['nombreComercial'] ?? '', 'ambiente' => (string)($emp['ambiente'] ?? '')],
    'licencia' => ['plan' => api_plan_nombre($ctx['licencia']), 'vence' => $ctx['licencia']['vence'] ?? null, 'estado' => $ctx['estado']['code'] ?? ''],
    'pcMinutos' => web_pc_minutos($ctx), 'ultimaSync' => $emp['_ultima_sync'] ?? null,
    'punto' => api_punto($ctx),
    'version' => FX_VERSION, 'api' => API_VERSION,
  ];
}

function api_punto(array $ctx): ?array {
  if (!web_puede($ctx, 'facturar')) return null;
  try { return emi_punto_info($ctx); } catch (Throwable $e) { return null; }
}

function api_datos_facturar(array $ctx): array {
  web_exigir($ctx, 'facturar');
  $tarifas = web_tarifas($ctx);
  $lista = [];
  foreach ($tarifas as $k => $t) $lista[] = ['codigo' => (string)$k, 'pct' => (float)$t['pct'], 'nombre' => $t['label']];
  $formas = [];
  foreach (WEB_FORMAS_PAGO as $k => $t) $formas[] = ['codigo' => (string)$k, 'nombre' => $t];
  return ['tarifas' => $lista, 'tarifaDefecto' => isset($tarifas['4']) ? '4' : (string)array_key_first($tarifas), 'formasPago' => $formas,
    'formaPago' => (string)($ctx['empresa']['formaPagoDefecto'] ?? '20'), 'limiteCF' => 50, 'bloquearSinStock' => !empty(web_cfg_inventario($ctx)['bloquearSinStock']),
    'rapidos' => web_rapidos($ctx), 'aviso' => api_aviso($ctx), 'punto' => api_punto($ctx)];
}

// Nombre del plan como se vende (Básico, Profesional, Completo) según las funciones de la licencia.
function api_plan_nombre(array $l): string {
  $f = funciones_licencia($l);
  $pro = count(array_intersect(FX_PRO, $f)) === count(FX_PRO);
  $n = in_array('nube', $f, true) && in_array('movil', $f, true) ? ($pro ? 'Completo' : 'Web') : ($pro ? 'Profesional' : 'Básico');
  return $n . (in_array('contabilidad', $f, true) ? ' + Contabilidad' : '');
}

function api_doc(array $c): array {
  $est = WEB_ESTADOS[$c['estado'] ?? ''] ?? [(string)($c['estado'] ?? ''), 'n'];
  // Las facturas hechas en la web o en la app esperan a que el programa las firme.
  if (($c['estado'] ?? '') === 'GENERADO' && (web_json($c['data'] ?? [])['origen'] ?? '') === 'WEB') $est = WEB_ESTADOS['WEB'];
  $vencido = web_vencido($c);
  return ['id' => $c['id'], 'codDoc' => (string)($c['cod_doc'] ?? $c['tipo'] ?? ''), 'tipo' => WEB_DOCS[$c['cod_doc'] ?? ''] ?? 'Comprobante', 'numero' => $c['numero'] ?? '',
    'fecha' => substr((string)($c['fecha'] ?? ''), 0, 10), 'cliente' => $c['cliente_nombre'] ?? '', 'identificacion' => $c['cliente_identificacion'] ?? '',
    'total' => (float)($c['total'] ?? 0), 'estado' => $c['estado'] ?? '', 'estadoNombre' => $vencido ? 'Vencida · no llegó al SRI' : $est[0], 'tono' => $vencido ? 'r' : $est[1],
    'vencido' => $vencido, 'anulado' => !empty($c['anulado'])];
}

function api_doc_detalle(array $ctx, array $c): array {
  $d = web_json($c['data'] ?? []);
  $cli = $d['cliente'] ?? ['nombre' => $c['cliente_nombre'] ?? '', 'identificacion' => $c['cliente_identificacion'] ?? ''];
  $tot = $d['totales'] ?? [];
  $items = array_map(fn($it) => ['codigo' => (string)($it['codigo'] ?? ''), 'descripcion' => (string)($it['descripcion'] ?? ''), 'cantidad' => (float)($it['cantidad'] ?? 0),
    'precioUnitario' => (float)($it['precioUnitario'] ?? 0), 'descuento' => (float)($it['descuento'] ?? 0), 'subtotal' => (float)($it['base'] ?? ((float)($it['cantidad'] ?? 0) * (float)($it['precioUnitario'] ?? 0)))], $d['items'] ?? []);
  $impuestos = array_map(fn($i) => ['tarifa' => (string)($i['tarifa'] ?? ''), 'base' => (float)($i['base'] ?? 0), 'valor' => (float)($i['valor'] ?? 0)], $tot['impuestos'] ?? []);
  $esWeb = ($d['origen'] ?? '') === 'WEB';
  $sync = $ctx['empresa']['_ultima_sync'] ?? null;
  $mensajes = array_values(array_filter(array_map(fn($m) => is_array($m) ? trim((string)($m['mensaje'] ?? '') . ' ' . (string)($m['informacionAdicional'] ?? '')) : (string)$m, web_json($c['mensajes'] ?? []))));
  return array_merge(api_doc($c), [
    'cliente' => ['nombre' => $cli['nombre'] ?? '', 'identificacion' => $cli['identificacion'] ?? '', 'email' => $cli['email'] ?? '', 'direccion' => $cli['direccion'] ?? '', 'telefono' => $cli['telefono'] ?? ''],
    'items' => $items, 'impuestos' => $impuestos, 'descuento' => (float)($tot['totalDescuento'] ?? 0), 'subtotal' => (float)($tot['totalSinImpuestos'] ?? 0),
    'formaPago' => isset($d['formaPago']) ? (WEB_FORMAS_PAGO[$d['formaPago']] ?? (string)$d['formaPago']) : '', 'diasCredito' => (int)($d['diasCredito'] ?? 0),
    'claveAcceso' => $c['clave_acceso'] ?? '', 'mensajes' => array_slice($mensajes, 0, 5),
    'origenWeb' => $esWeb, 'porFirmar' => $esWeb && in_array($c['estado'] ?? '', WEB_POR_AUTORIZAR, true), 'pcActiva' => $sync && time() - strtotime($sync) < 900,
    'rideUrl' => nube_enlace_publico($ctx['lic'], $ctx['perfil'], (string)$c['id'], 'ride', 7),
    'xmlUrl' => nube_enlace_publico($ctx['lic'], $ctx['perfil'], (string)$c['id'], 'xml', 7),
  ]);
}

function api_aviso(array $ctx): ?array {
  $pa = web_por_autorizar($ctx);
  $min = web_pc_minutos($ctx);
  if ($pa['vencidos'] > 0) return ['tono' => 'r', 'titulo' => $pa['vencidos'] === 1 ? 'Un comprobante no llegó al SRI el día de su emisión' : $pa['vencidos'] . ' comprobantes no llegaron al SRI el día de su emisión', 'texto' => 'Vuélvelos a emitir con la fecha de hoy desde el programa (Autorizaciones). Se mantiene el mismo número.'];
  if ($pa['hoy'] > 0) {
    $h = (int)date('G');
    $apagada = $min === null || $min > 15;
    return ['tono' => $h >= 19 ? 'r' : ($h >= 15 ? 'a' : 'b'), 'titulo' => $pa['hoy'] === 1 ? 'Queda 1 comprobante por autorizar hoy' : 'Quedan ' . $pa['hoy'] . ' comprobantes por autorizar hoy',
      'texto' => $apagada ? 'La computadora de la tienda está apagada o sin internet' . ($min !== null ? ' (último contacto ' . web_hace($ctx['empresa']['_ultima_sync'] ?? '') . ')' : '') . '. Enciéndela hoy: el SRI solo acepta lo transmitido el mismo día.' : 'Tu programa los está enviando al SRI.'];
  }
  return null;
}

$a = preg_replace('/[^a-z_]/', '', (string)($_GET['a'] ?? ''));
try {
  if (!is_file(dirname(__DIR__) . '/config.php')) api_error('Servicio no instalado.', 503);
  nube_esquema();
  api_esquema();
  $post = $_SERVER['REQUEST_METHOD'] === 'POST';

  if ($a === 'salud') api_salida(['version' => FX_VERSION, 'api' => API_VERSION]);

  if ($a === 'login') {
    if (!$post) api_error('Usa POST.', 405);
    $in = api_entrada();
    if (web_bloqueado()) api_error('Demasiados intentos. Espera 15 minutos.', 429);
    $u = nube_usuario_por_nombre(web_txt_in($in, 'usuario', 60));
    $clave = (string)($in['clave'] ?? '');
    if (!$u || !(int)$u['activo'] || !password_verify($clave, $u['hash'])) {
      db()->prepare("INSERT INTO intentos (ip, tipo, creado) VALUES (?, 'web', ?)")->execute([ip_cliente(), date('Y-m-d H:i:s')]);
      api_error('Usuario o contraseña incorrectos.', 401, 'CREDENCIALES');
    }
    if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE nube_usuarios SET hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    $ctx = web_contexto_usuario((int)$u['id']);
    if (!$ctx) api_error('La licencia de este usuario ya no existe.', 403);
    $token = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    db()->prepare('INSERT INTO nube_app_tokens (hash, usuario_id, perfil, equipo, creado, ultimo_uso) VALUES (?, ?, ?, ?, ?, ?)')
      ->execute([hash('sha256', $token), (int)$u['id'], $ctx['perfil'], web_txt_in($in, 'equipo', 80), ahora(), ahora()]);
    db()->prepare('UPDATE nube_usuarios SET ultimo_acceso = ? WHERE id = ?')->execute([ahora(), $u['id']]);
    api_salida(['token' => $token] + api_yo($ctx));
  }

  $ctx = api_contexto();
  if ($a === 'yo') api_salida(api_yo($ctx));
  if ($a === 'salir') { db()->prepare('DELETE FROM nube_app_tokens WHERE hash = ?')->execute([$ctx['_token']]); api_salida([]); }
  if ($a === 'perfil') {
    $p = web_txt_in(api_entrada(), 'perfil', 13);
    if (!in_array($p, $ctx['perfiles'], true)) api_error('Esa empresa no pertenece a tu licencia.');
    db()->prepare('UPDATE nube_app_tokens SET perfil = ? WHERE hash = ?')->execute([$p, $ctx['_token']]);
    api_salida(api_yo(web_contexto_usuario((int)$ctx['usuario']['id'], $p)));
  }
  if ($ctx['bloqueo'] !== '') api_error($ctx['bloqueo'], 403, 'BLOQUEO');

  switch ($a) {
    case 'inicio': {
      $res = web_resumen($ctx);
      $dias = [];
      foreach (web_ventas_dias($ctx, 14) as $f => $t) $dias[] = ['fecha' => $f, 'total' => round($t, 2)];
      [$ultimos] = web_lista($ctx, 'comprobantes', [], 6);
      api_salida(['mes' => ['n' => (int)$res['mes']['n'], 'total' => (float)$res['mes']['t']], 'hoy' => ['n' => (int)$res['hoy']['n'], 'total' => (float)$res['hoy']['t']],
        'pendientes' => $res['pendientes'], 'rechazados' => $res['rechazados'], 'clientes' => $res['clientes'], 'enCola' => $res['pedidos'],
        'dias' => $dias, 'ultimos' => array_map('api_doc', $ultimos), 'aviso' => api_aviso($ctx), 'pcMinutos' => web_pc_minutos($ctx)]);
    }
    case 'comprobantes': {
      $f = ['q' => web_txt_in($_GET, 'q', 80), 'tipo' => (string)($_GET['tipo'] ?? ''), 'estado' => (string)($_GET['estado'] ?? '')];
      if (!isset(WEB_DOCS[$f['tipo']])) $f['tipo'] = '';
      if (!in_array($f['estado'], ['', 'AUTORIZADO', 'PENDIENTES', 'RECHAZADOS'], true)) $f['estado'] = '';
      $pagina = max(1, (int)($_GET['p'] ?? 1)); $por = 30;
      [$docs, $total] = web_lista($ctx, 'comprobantes', $f, $por, ($pagina - 1) * $por);
      api_salida(['total' => $total, 'pagina' => $pagina, 'hayMas' => $pagina * $por < $total, 'items' => array_map('api_doc', $docs)]);
    }
    case 'comprobante': {
      $c = web_registro($ctx, 'comprobantes', web_txt_in($_GET, 'id', 64));
      if (!$c || !empty($c['deleted'])) api_error('No se encontró el comprobante.', 404);
      api_salida(['comprobante' => api_doc_detalle($ctx, $c)]);
    }
    case 'clientes': {
      $pagina = max(1, (int)($_GET['p'] ?? 1)); $por = 40;
      [$filas, $total] = web_lista($ctx, 'clientes', ['q' => web_txt_in($_GET, 'q', 80)], $por, ($pagina - 1) * $por);
      api_salida(['total' => $total, 'hayMas' => $pagina * $por < $total, 'items' => array_map('web_cliente_salida', $filas)]);
    }
    case 'productos': {
      $pagina = max(1, (int)($_GET['p'] ?? 1)); $por = 40;
      [$filas, $total] = web_lista($ctx, 'productos', ['q' => web_txt_in($_GET, 'q', 80)], $por, ($pagina - 1) * $por);
      api_salida(['total' => $total, 'hayMas' => $pagina * $por < $total, 'items' => web_productos_salida($ctx, $filas)]);
    }
    case 'cliente_guardar': if (!$post) api_error('Usa POST.', 405); api_salida(['cliente' => web_cliente_salida(web_cliente_guardar($ctx, api_entrada()))]);
    case 'cliente_eliminar': if (!$post) api_error('Usa POST.', 405); web_cliente_eliminar($ctx, web_txt_in(api_entrada(), 'id', 64)); api_salida([]);
    case 'producto_guardar': if (!$post) api_error('Usa POST.', 405); api_salida(['producto' => web_productos_salida($ctx, [web_producto_guardar($ctx, api_entrada())])[0]]);
    case 'producto_eliminar': if (!$post) api_error('Usa POST.', 405); web_producto_eliminar($ctx, web_txt_in(api_entrada(), 'id', 64)); api_salida([]);
    case 'inventario': {
      web_inv_exigir($ctx);
      $b = isset($_GET['b']) && $_GET['b'] !== '*' ? (string)$_GET['b'] : null;
      $bodegas = web_bodegas($ctx);
      if ($b !== null && !in_array($b, array_column($bodegas, 'id'), true)) $b = null;
      $lista = web_inv_existencias($ctx, web_txt_in($_GET, 'q', 80), $b, !empty($_GET['bajo']));
      api_salida(['resumen' => web_inv_resumen(web_inv_existencias($ctx, '', $b)), 'bodegas' => array_map(fn($x) => ['id' => $x['id'], 'nombre' => $x['nombre'], 'codigo' => $x['codigo']], $bodegas),
        'items' => $lista, 'pedidos' => web_inv_pedidos($ctx, 15), 'puedeEditar' => web_puede($ctx, 'editar'), 'ultimaSync' => $ctx['empresa']['_ultima_sync'] ?? null]);
    }
    case 'inventario_kardex': {
      web_inv_exigir($ctx);
      $b = isset($_GET['b']) && $_GET['b'] !== '*' && $_GET['b'] !== null ? (string)$_GET['b'] : null;
      api_salida(web_inv_kardex($ctx, web_txt_in($_GET, 'id', 64), $b, 150));
    }
    case 'inventario_registrar': if (!$post) api_error('Usa POST.', 405); $p = web_inv_registrar($ctx, api_entrada()); api_salida(['id' => $p['id'], 'mensaje' => INV_OPERACIONES[$p['operacion']] . ' registrado. Tu programa lo aplica al sincronizar.']);
    case 'inventario_cancelar': if (!$post) api_error('Usa POST.', 405); web_inv_cancelar($ctx, web_txt_in(api_entrada(), 'id', 64)); api_salida([]);
    case 'facturar_datos': api_salida(api_datos_facturar($ctx));
    case 'offline_datos': {
      // Todo lo necesario para facturar sin internet: datos de facturación, catálogo completo y clientes recientes.
      $d = api_datos_facturar($ctx);
      [$prods] = web_lista($ctx, 'productos', [], 3000);
      [$clis] = web_lista($ctx, 'clientes', [], 1500);
      api_salida($d + ['productos' => web_productos_salida($ctx, $prods), 'clientes' => array_map('web_cliente_salida', $clis), 'generado' => web_ahora_iso()]);
    }
    case 'punto_cambiar': {
      if (!$post) api_error('Usa POST.', 405);
      api_salida(['punto' => emi_cambiar_punto($ctx, web_txt_in(api_entrada(), 'modo', 10))]);
    }
    case 'reservar': {
      if (!$post) api_error('Usa POST.', 405);
      $in = api_entrada();
      api_salida(['reserva' => emi_reservar($ctx, web_txt_in($in, 'dispositivo', 36), (int)($in['cantidad'] ?? 10))]);
    }
    case 'facturar_offline': {
      if (!$post) api_error('Usa POST.', 405);
      $in = api_entrada();
      // La factura se hizo con la empresa que estaba elegida en ese momento (puede no ser la de ahora).
      $p = web_txt_in($in, 'perfil', 13);
      if ($p !== '' && $p !== $ctx['perfil']) {
        if (!in_array($p, $ctx['perfiles'], true)) api_error('Esa empresa no pertenece a tu licencia.');
        $ctx = web_contexto_usuario((int)$ctx['usuario']['id'], $p) ?: api_error('Esa empresa no pertenece a tu licencia.');
        if ($ctx['bloqueo'] !== '') api_error($ctx['bloqueo'], 403, 'BLOQUEO');
      }
      $c = emi_facturar_offline($ctx, $in);
      web_avisar_dueno($ctx);
      $reg = web_registro($ctx, 'comprobantes', (string)$c['id']) ?? $c;
      api_salida(['comprobante' => api_doc_detalle($ctx, $reg)]);
    }
    case 'facturar': {
      if (!$post) api_error('Usa POST.', 405);
      $c = web_facturar($ctx, api_entrada(), 'App');
      $reg = web_registro($ctx, 'comprobantes', (string)$c['id']) ?? $c;
      api_salida(['comprobante' => api_doc_detalle($ctx, $reg)]);
    }
  }
  api_error('Acción no válida.', 404);
} catch (WebError $ex) {
  api_error($ex->getMessage(), 422);
} catch (Throwable $ex) {
  error_log('[api-app] ' . $ex->getMessage() . ' @' . $ex->getFile() . ':' . $ex->getLine());
  api_error('Ocurrió un error en el servidor. Intenta de nuevo en unos minutos.', 500);
}
