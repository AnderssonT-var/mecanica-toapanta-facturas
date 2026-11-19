<?php
// Inventario completo en la app web y en la de Android: existencias por bodega, stock bajo, valorizado, kardex y
// movimientos (ingreso de mercadería, ajuste y transferencia). El stock lo calcula el programa (es la fuente): la web
// lee el resumen que sube («existencias») y los movimientos (si la licencia tiene la nube). Lo que se hace aquí viaja
// como pedido de inventario («INV») que el programa aplica en su kardex al sincronizar (desde Facmit 0.25).
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }

const INV_OPERACIONES = ['INGRESO' => 'Ingreso de mercadería', 'AJUSTE' => 'Ajuste de stock', 'TRANSFERENCIA' => 'Transferencia entre bodegas'];
const INV_TIPOS_MOV = ['INICIAL' => 'Saldo inicial', 'INGRESO' => 'Ingreso', 'VENTA' => 'Venta', 'DEVOLUCION' => 'Devolución', 'AJUSTE' => 'Ajuste',
  'COMPRA' => 'Compra', 'DEV_COMPRA' => 'Devolución a proveedor', 'TRANSFERENCIA' => 'Transferencia'];

function web_inv_activo(array $ctx): bool { return tiene_funcion($ctx['licencia'], 'inventario'); }
function web_inv_exigir(array $ctx, string $permiso = 'ver'): void {
  web_exigir($ctx, $permiso);
  if (!web_inv_activo($ctx)) throw new WebError('Tu plan no incluye el inventario completo. Pide agregar «Inventario completo» a tu licencia.');
}

// Bodegas activas del programa; la principal no se guarda como fila (su id es vacío).
function web_bodegas(array $ctx): array {
  $salida = [['id' => '', 'codigo' => 'PRI', 'nombre' => (string)($ctx['empresa']['bodegaPrincipal'] ?? '') ?: 'Bodega principal', 'estab' => '', 'principal' => true]];
  [$filas] = web_lista($ctx, 'bodegas', [], 200);
  foreach ($filas as $b) {
    if (!empty($b['deleted']) || (isset($b['activa']) && !(int)$b['activa'])) continue;
    $salida[] = ['id' => (string)$b['id'], 'codigo' => (string)($b['codigo'] ?? ''), 'nombre' => (string)($b['nombre'] ?? ''), 'estab' => (string)($b['estab'] ?? ''), 'principal' => false];
  }
  return $salida;
}

function web_inv_num($v): float { return round((float)$v, 6); }

// Productos con control de stock y su stock en cada bodega (lo último que informó el programa).
// $bodega: null = todas; '' = principal; id = esa bodega. $soloBajo: stock en o bajo el mínimo.
function web_inv_existencias(array $ctx, string $q = '', ?string $bodega = null, bool $soloBajo = false): array {
  [$prods] = web_lista($ctx, 'productos', ['q' => $q], 2000);
  $porProducto = [];
  $r = db()->prepare("SELECT datos FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'existencias' AND borrado = 0");
  $r->execute([$ctx['lic'], $ctx['perfil']]);
  foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $j) {
    $e = json_decode($j, true) ?: [];
    if (!empty($e['deleted'])) continue;
    $porProducto[(string)($e['producto_id'] ?? '')][(string)($e['bodega_id'] ?? '')] = web_inv_num($e['stock'] ?? 0);
  }
  $nombres = array_column(web_bodegas($ctx), 'nombre', 'id');
  $salida = [];
  foreach ($prods as $p) {
    if (!empty($p['deleted'])) continue;
    $controla = !empty($p['controla_stock']) || isset($porProducto[$p['id']]);
    if (!$controla) continue;
    $pb = $porProducto[$p['id']] ?? [];
    $stock = $bodega === null ? array_sum($pb) : ($pb[$bodega] ?? 0.0);
    $min = (float)($p['stock_minimo'] ?? 0);
    $costo = (float)($p['costo'] ?? 0);
    $fila = ['id' => (string)$p['id'], 'codigo' => (string)$p['codigo'], 'descripcion' => (string)($p['descripcion'] ?? ''), 'linea' => (string)($p['linea'] ?? ''), 'unidad' => (string)($p['unidad'] ?? ''),
      'precio' => (float)($p['precio'] ?? 0), 'costo' => $costo, 'stockMinimo' => $min, 'stockMaximo' => (float)($p['stock_maximo'] ?? 0), 'controlaLote' => !empty($p['controla_lote']),
      'stock' => round($stock, 6), 'valor' => round(max(0, $stock) * $costo, 2), 'bajo' => $stock <= $min, 'agotado' => $stock <= 0,
      'bodegas' => array_values(array_map(fn($id, $s) => ['id' => (string)$id, 'nombre' => $nombres[$id] ?? 'Bodega', 'stock' => $s], array_keys($pb), $pb))];
    if ($soloBajo && !$fila['bajo']) continue;
    $salida[] = $fila;
  }
  usort($salida, fn($a, $b) => strcasecmp($a['descripcion'], $b['descripcion']));
  return $salida;
}

function web_inv_resumen(array $lista): array {
  return ['productos' => count($lista), 'bajo' => count(array_filter($lista, fn($p) => $p['bajo'])), 'agotados' => count(array_filter($lista, fn($p) => $p['agotado'])),
    'valor' => round(array_sum(array_column($lista, 'valor')), 2), 'unidades' => round(array_sum(array_map(fn($p) => max(0, $p['stock']), $lista)), 2)];
}

// Kardex de un producto con los movimientos que subió el programa (licencias con la nube).
// Devuelve ['disponible' => false] si el programa no sube movimientos (portal sin nube de respaldo).
function web_inv_kardex(array $ctx, string $productoId, ?string $bodega = null, int $limite = 200): array {
  $hay = db()->prepare("SELECT 1 FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'movimientos' LIMIT 1");
  $hay->execute([$ctx['lic'], $ctx['perfil']]);
  if (!$hay->fetchColumn()) return ['disponible' => false, 'filas' => [], 'saldo' => 0.0];
  $q = db()->prepare("SELECT datos FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'movimientos' AND borrado = 0 AND datos LIKE ?");
  $q->execute([$ctx['lic'], $ctx['perfil'], '%"producto_id":"' . str_replace(['%', '_'], ['\\%', '\\_'], $productoId) . '"%']);
  $movs = [];
  foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $j) {
    $m = json_decode($j, true) ?: [];
    if (!empty($m['deleted']) || (string)($m['producto_id'] ?? '') !== $productoId) continue;
    if ($bodega !== null && (string)($m['bodega_id'] ?? '') !== $bodega) continue;
    // Sin filtro de bodega las transferencias no cambian el total: no se muestran (igual que el programa).
    if ($bodega === null && ($m['tipo'] ?? '') === 'TRANSFERENCIA') continue;
    $movs[] = $m;
  }
  usort($movs, fn($a, $b) => strcmp((string)($a['fecha'] ?? ''), (string)($b['fecha'] ?? '')) ?: strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
  $nombres = array_column(web_bodegas($ctx), 'nombre', 'id');
  $saldo = 0.0;
  $filas = [];
  foreach ($movs as $m) {
    $c = (float)($m['cantidad'] ?? 0);
    $saldo = round($saldo + $c, 6);
    $filas[] = ['fecha' => (string)($m['fecha'] ?? ''), 'tipo' => INV_TIPOS_MOV[$m['tipo'] ?? ''] ?? (string)($m['tipo'] ?? ''), 'documento' => (string)($m['documento'] ?? ''),
      'entrada' => $c > 0 ? $c : 0.0, 'salida' => $c < 0 ? -$c : 0.0, 'saldo' => $saldo, 'costo' => (float)($m['costo'] ?? 0), 'nota' => (string)($m['nota'] ?? ''),
      'usuario' => (string)($m['usuario'] ?? ''), 'bodega' => $nombres[(string)($m['bodega_id'] ?? '')] ?? 'Bodega', 'lote' => (string)($m['lote'] ?? '')];
  }
  return ['disponible' => true, 'filas' => array_reverse(array_slice($filas, -$limite)), 'saldo' => $saldo];
}

// Movimientos de inventario pedidos desde la web o la app (los pendientes primero).
function web_inv_pedidos(array $ctx, int $limite = 30): array {
  $q = db()->prepare("SELECT datos FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'pedidos' AND tipo = 'INV' AND borrado = 0 ORDER BY fecha DESC LIMIT " . max(1, $limite * 2));
  $q->execute([$ctx['lic'], $ctx['perfil']]);
  $lista = array_map(fn($j) => json_decode($j, true) ?: [], $q->fetchAll(PDO::FETCH_COLUMN));
  usort($lista, fn($a, $b) => strcmp((string)($b['creado'] ?? ''), (string)($a['creado'] ?? '')));
  return array_slice(array_map(fn($p) => [
    'id' => (string)$p['id'], 'operacion' => (string)($p['operacion'] ?? ''), 'nombre' => INV_OPERACIONES[$p['operacion'] ?? ''] ?? 'Movimiento', 'estado' => (string)($p['estado'] ?? ''),
    'creado' => (string)($p['creado'] ?? ''), 'usuario' => (string)(($p['usuarioNombre'] ?? '') ?: ($p['usuario'] ?? '')), 'documento' => (string)($p['documento'] ?? ''), 'mensaje' => (string)($p['mensaje'] ?? ''),
    'resumen' => web_inv_texto_pedido($p),
  ], $lista), 0, $limite);
}

function web_inv_texto_pedido(array $p): string {
  $lin = $p['lineas'] ?? [];
  $n = count($lin);
  switch ($p['operacion'] ?? '') {
    case 'AJUSTE': return ($p['descripcion'] ?? $p['codigo'] ?? '') . ': contado ' . web_cant($p['contado'] ?? 0) . ' en ' . ($p['bodegaNombre'] ?? 'bodega') . ' · ' . ($p['motivo'] ?? '');
    case 'TRANSFERENCIA': return $n . ' producto' . ($n === 1 ? '' : 's') . ' de ' . ($p['origenNombre'] ?? '') . ' a ' . ($p['destinoNombre'] ?? '');
    default: return $n . ' producto' . ($n === 1 ? '' : 's') . ' a ' . ($p['bodegaNombre'] ?? 'bodega') . (($p['proveedor'] ?? '') !== '' ? ' · ' . $p['proveedor'] : '');
  }
}

// Valida y guarda un movimiento de inventario para que lo aplique el programa.
function web_inv_registrar(array $ctx, array $in): array {
  web_inv_exigir($ctx, 'editar');
  // El programa aplica estos movimientos desde la versión 0.25; con una anterior quedarían sin aplicar.
  $prog = (string)($ctx['empresa']['programa'] ?? '');
  if ($prog !== '' && version_compare($prog, '0.25.0', '<')) throw new WebError('Actualiza Facmit en la computadora de la tienda (tiene la ' . $prog . '; se necesita la 0.25 o más reciente) para registrar movimientos de inventario desde aquí.');
  $op = (string)($in['operacion'] ?? '');
  if (!isset(INV_OPERACIONES[$op])) throw new WebError('Elige qué movimiento quieres registrar.');
  $bodegas = array_column(web_bodegas($ctx), null, 'id');
  $bod = function ($id, string $que) use ($bodegas): array {
    $id = is_scalar($id) ? (string)$id : '';
    if (!isset($bodegas[$id])) throw new WebError('La bodega ' . $que . ' no existe o está desactivada.');
    return $bodegas[$id];
  };
  [$catalogo] = web_lista($ctx, 'productos', [], 5000);
  $porCodigo = [];
  foreach ($catalogo as $p) if (empty($p['deleted'])) $porCodigo[mb_strtolower((string)$p['codigo'])] = $p;
  $producto = function ($codigo) use ($porCodigo): array {
    $c = mb_strtolower(trim(is_scalar($codigo) ? (string)$codigo : ''));
    if ($c === '' || !isset($porCodigo[$c])) throw new WebError('El producto «' . $codigo . '» no está en el catálogo.');
    return $porCodigo[$c];
  };
  $num = function ($v, string $que, bool $cero = false): float {
    $n = emi_num_ok($v);
    if (is_nan($n) || $n < 0 || (!$cero && $n <= 0) || $n > 1e9) throw new WebError($que);
    return round($n, 6);
  };
  $base = ['tipo' => 'INV', 'operacion' => $op, 'estado' => 'PENDIENTE', 'creado' => web_ahora_iso(), 'usuario' => $ctx['usuario']['usuario'] ?? '', 'usuarioNombre' => $ctx['usuario']['nombre'] ?? '',
    'nota' => web_txt_in($in, 'nota', 150), 'total' => 0];
  if ($op === 'AJUSTE') {
    $p = $producto($in['codigo'] ?? '');
    $b = $bod($in['bodegaId'] ?? '', 'elegida');
    $motivo = web_txt_in($in, 'motivo', 200);
    if (mb_strlen($motivo) < 3) throw new WebError('Escribe el motivo del ajuste (por ejemplo: conteo físico, producto dañado).');
    $contado = $num($in['contado'] ?? '', 'La cantidad contada no es válida.', true);
    $lote = !empty($p['controla_lote']) ? mb_strtoupper(web_txt_in($in, 'lote', 30)) : '';
    $pedido = $base + ['codigo' => $p['codigo'], 'descripcion' => $p['descripcion'] ?? '', 'bodegaId' => $b['id'], 'bodegaNombre' => $b['nombre'], 'contado' => $contado, 'motivo' => $motivo, 'lote' => $lote];
  } else {
    $lineas = [];
    $vistos = [];
    foreach ((is_array($in['lineas'] ?? null) ? $in['lineas'] : []) as $l) {
      if (!is_array($l)) continue;
      $codigo = trim(is_scalar($l['codigo'] ?? null) ? (string)$l['codigo'] : '');
      if ($codigo === '' && emi_num_ok($l['cantidad'] ?? 0) <= 0) continue;
      $p = $producto($codigo);
      $cant = $num($l['cantidad'] ?? '', 'La cantidad de «' . ($p['descripcion'] ?? $codigo) . '» no es válida.');
      $linea = ['codigo' => $p['codigo'], 'descripcion' => $p['descripcion'] ?? '', 'cantidad' => $cant];
      if ($op === 'INGRESO') {
        $costo = ($l['costo'] ?? '') === '' || ($l['costo'] ?? null) === null ? (float)($p['costo'] ?? 0) : $num($l['costo'], 'El costo de «' . ($p['descripcion'] ?? $codigo) . '» no es válido.', true);
        $linea['costo'] = round($costo, 6);
        if (!empty($p['controla_lote'])) {
          $linea['lote'] = mb_strtoupper(web_txt_in($l, 'lote', 30));
          $vence = web_txt_in($l, 'vence', 10);
          if ($linea['lote'] === '') throw new WebError('«' . ($p['descripcion'] ?? $codigo) . '» se controla por lote: escribe el lote.');
          if ($vence !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence)) throw new WebError('La fecha de vencimiento de «' . ($p['descripcion'] ?? $codigo) . '» no es válida.');
          $linea['vence'] = $vence;
        }
        $base['total'] += $cant * $linea['costo'];
      } elseif (!empty($p['controla_lote'])) {
        $linea['lote'] = mb_strtoupper(web_txt_in($l, 'lote', 30));
      }
      $clave = mb_strtolower($p['codigo']) . '|' . ($linea['lote'] ?? '');
      if (isset($vistos[$clave])) throw new WebError('«' . ($p['descripcion'] ?? $codigo) . '» está repetido. Suma las cantidades en una sola línea.');
      $vistos[$clave] = true;
      $lineas[] = $linea;
    }
    if (!$lineas) throw new WebError('Agrega al menos un producto con cantidad.');
    if (count($lineas) > 200) throw new WebError('Máximo 200 productos por movimiento.');
    if ($op === 'INGRESO') {
      $b = $bod($in['bodegaId'] ?? '', 'de destino');
      $pedido = $base + ['bodegaId' => $b['id'], 'bodegaNombre' => $b['nombre'], 'documento' => web_txt_in($in, 'documento', 40), 'proveedor' => web_txt_in($in, 'proveedor', 120), 'lineas' => $lineas];
      $pedido['total'] = round($pedido['total'], 2);
    } else {
      $o = $bod($in['origen'] ?? '', 'de origen');
      $d = $bod($in['destino'] ?? '', 'de destino');
      if ($o['id'] === $d['id']) throw new WebError('Elige bodegas de origen y destino distintas.');
      $pedido = $base + ['origen' => $o['id'], 'origenNombre' => $o['nombre'], 'destino' => $d['id'], 'destinoNombre' => $d['nombre'], 'lineas' => $lineas];
    }
  }
  return web_guardar($ctx, 'pedidos', $pedido);
}

// Un movimiento que el programa todavía no toma se puede cancelar.
function web_inv_cancelar(array $ctx, string $id): void {
  web_inv_exigir($ctx, 'editar');
  $p = web_registro($ctx, 'pedidos', $id);
  if (!$p || ($p['tipo'] ?? '') !== 'INV') throw new WebError('No se encontró el movimiento.');
  if (!nube_cambiar_pedido($ctx['lic'], $ctx['perfil'], $id, 'PENDIENTE', 'CANCELADO', ['mensaje' => 'Cancelado por ' . ($ctx['usuario']['usuario'] ?? '')])) {
    throw new WebError('Tu programa ya está aplicando este movimiento; no se puede cancelar.');
  }
}
