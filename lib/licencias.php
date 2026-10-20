<?php
// Reglas de negocio de las licencias (usadas por el panel y por la API del programa).
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }

class ErrorLicencia extends RuntimeException {
  public string $codigo;
  public function __construct(string $codigo, string $mensaje) {
    parent::__construct($mensaje);
    $this->codigo = $codigo;
  }
}

function validar_datos_licencia(array $d, bool $nueva): array {
  $t = fn($k, $max = 200) => mb_substr(trim((string)($d[$k] ?? '')), 0, $max);
  $n = fn($k, $min, $max, $def) => max($min, min($max, (int)($d[$k] ?? $def)));
  $l = [
    'cliente' => mb_strtoupper($t('cliente')),
    'ruc' => preg_replace('/\D/', '', $t('ruc', 13)),
    'email' => $t('email', 160),
    'telefono' => $t('telefono', 40),
    'plan' => strtoupper($t('plan', 10)),
    'inicio' => $t('inicio', 10),
    'vence' => $t('vence', 10),
    'gracia' => $n('gracia', 0, 30, 3),
    'empresas' => $n('empresas', 1, 50, 1),
    'equipos_max' => $n('equipos_max', 1, 100, 1),
    'usuarios' => $n('usuarios', 1, 200, 1),
    'puntos' => $n('puntos', 1, 100, 1),
    'precio' => round(max(0, (float)str_replace(',', '.', (string)($d['precio'] ?? 0))), 2),
    'periodo' => in_array($d['periodo'] ?? '', ['MENSUAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL'], true) ? $d['periodo'] : 'MENSUAL',
    'notas' => $t('notas', 2000),
  ];
  $rucs = [];
  foreach (preg_split('/[\s,;]+/', (string)($d['rucs'] ?? '')) as $r) {
    $r = preg_replace('/\D/', '', $r);
    if ($r === '' || $r === $l['ruc']) continue;
    if (!validar_ruc($r)) throw new ErrorLicencia('DATOS', 'El RUC adicional ' . $r . ' no es válido.');
    if (!in_array($r, $rucs, true)) $rucs[] = $r;
  }
  $l['rucs'] = json_encode($rucs);
  if ($l['cliente'] === '') throw new ErrorLicencia('DATOS', 'Escribe el nombre o razón social del cliente.');
  if (!validar_ruc($l['ruc'])) throw new ErrorLicencia('DATOS', 'El RUC del cliente no es válido.');
  if ($l['email'] !== '' && !filter_var($l['email'], FILTER_VALIDATE_EMAIL)) throw new ErrorLicencia('DATOS', 'El email no es válido.');
  if (!isset(FX_PLANES[$l['plan']])) throw new ErrorLicencia('DATOS', 'Elige un plan.');
  foreach (['inicio', 'vence'] as $k) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $l[$k]) || !checkdate((int)substr($l[$k], 5, 2), (int)substr($l[$k], 8, 2), (int)substr($l[$k], 0, 4))) {
      throw new ErrorLicencia('DATOS', 'La fecha de ' . $k . ' no es válida.');
    }
  }
  if ($l['vence'] < $l['inicio']) throw new ErrorLicencia('DATOS', 'La fecha de vencimiento debe ser posterior al inicio.');
  if (count($rucs) + 1 > $l['empresas']) $l['empresas'] = count($rucs) + 1;
  return $l;
}

function crear_licencia(array $datos): int {
  $l = validar_datos_licencia($datos, true);
  $d = db();
  for ($i = 0; $i < 5; $i++) {
    $clave = nueva_clave();
    $r = $d->prepare('SELECT 1 FROM licencias WHERE clave = ?');
    $r->execute([$clave]);
    if (!$r->fetchColumn()) break;
  }
  $d->prepare('INSERT INTO licencias (clave, cliente, ruc, rucs, email, telefono, plan, inicio, vence, gracia, empresas, equipos_max, usuarios, puntos, precio, periodo, estado, notas, creado, actualizado)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$clave, $l['cliente'], $l['ruc'], $l['rucs'], $l['email'], $l['telefono'], $l['plan'], $l['inicio'], $l['vence'], $l['gracia'],
      $l['empresas'], $l['equipos_max'], $l['usuarios'], $l['puntos'], $l['precio'], $l['periodo'], 'ACTIVA', $l['notas'], ahora(), ahora()]);
  $id = (int)$d->lastInsertId();
  registrar_evento($id, 'CREADA', 'Plan ' . $l['plan'] . ' hasta ' . $l['vence']);
  return $id;
}

function actualizar_licencia(int $id, array $datos): void {
  $ant = licencia_por_id($id);
  if (!$ant) throw new ErrorLicencia('NO_EXISTE', 'La licencia no existe.');
  $l = validar_datos_licencia($datos, false);
  db()->prepare('UPDATE licencias SET cliente = ?, ruc = ?, rucs = ?, email = ?, telefono = ?, plan = ?, inicio = ?, vence = ?, gracia = ?, empresas = ?,
    equipos_max = ?, usuarios = ?, puntos = ?, precio = ?, periodo = ?, notas = ?, actualizado = ? WHERE id = ?')
    ->execute([$l['cliente'], $l['ruc'], $l['rucs'], $l['email'], $l['telefono'], $l['plan'], $l['inicio'], $l['vence'], $l['gracia'], $l['empresas'],
      $l['equipos_max'], $l['usuarios'], $l['puntos'], $l['precio'], $l['periodo'], $l['notas'], ahora(), $id]);
  $cambios = [];
  foreach (['plan', 'vence', 'ruc', 'equipos_max', 'empresas'] as $k) if ((string)$ant[$k] !== (string)$l[$k]) $cambios[] = $k . ': ' . $ant[$k] . ' → ' . $l[$k];
  registrar_evento($id, 'EDITADA', $cambios ? implode('; ', $cambios) : 'Datos actualizados');
}

function renovar_licencia(int $id, int $meses, float $monto, string $metodo, string $referencia, string $nota, ?int $admin, string $fecha = ''): string {
  $l = licencia_por_id($id);
  if (!$l) throw new ErrorLicencia('NO_EXISTE', 'La licencia no existe.');
  if ($meses < 0 || $meses > 36) throw new ErrorLicencia('DATOS', 'Los meses deben estar entre 0 y 36.');
  if ($monto < 0) throw new ErrorLicencia('DATOS', 'El monto no es válido.');
  $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : hoy();
  // Si ya venció, la renovación cuenta desde hoy; si no, desde el vencimiento actual.
  $base = $l['vence'] < hoy() ? hoy() : $l['vence'];
  $nuevo = $meses > 0 ? sumar_meses($base, $meses) : $l['vence'];
  $d = db();
  $d->beginTransaction();
  try {
    $d->prepare('UPDATE licencias SET vence = ?, actualizado = ? WHERE id = ?')->execute([$nuevo, ahora(), $id]);
    $d->prepare('INSERT INTO pagos (licencia_id, fecha, monto, meses, metodo, referencia, nota, vence_anterior, vence_nuevo, admin_id, creado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$id, $fecha, round($monto, 2), $meses, mb_substr($metodo, 0, 40), mb_substr($referencia, 0, 120), mb_substr($nota, 0, 255), $l['vence'], $nuevo, $admin, ahora()]);
    $d->commit();
  } catch (Throwable $ex) {
    $d->rollBack();
    throw $ex;
  }
  registrar_evento($id, 'RENOVADA', $meses . ' mes(es), $' . number_format($monto, 2) . ', vence ' . $nuevo);
  return $nuevo;
}

function cambiar_estado_licencia(int $id, bool $suspender, string $motivo = ''): void {
  if (!licencia_por_id($id)) throw new ErrorLicencia('NO_EXISTE', 'La licencia no existe.');
  db()->prepare('UPDATE licencias SET estado = ?, motivo = ?, actualizado = ? WHERE id = ?')
    ->execute([$suspender ? 'SUSPENDIDA' : 'ACTIVA', $suspender ? mb_substr($motivo, 0, 255) : '', ahora(), $id]);
  registrar_evento($id, $suspender ? 'SUSPENDIDA' : 'REACTIVADA', $motivo);
}

function regenerar_clave(int $id): string {
  if (!licencia_por_id($id)) throw new ErrorLicencia('NO_EXISTE', 'La licencia no existe.');
  $clave = nueva_clave();
  db()->prepare('UPDATE licencias SET clave = ?, actualizado = ? WHERE id = ?')->execute([$clave, ahora(), $id]);
  // Los equipos activados quedan liberados: deben activar con la nueva clave.
  db()->prepare('UPDATE equipos SET activo = 0 WHERE licencia_id = ?')->execute([$id]);
  registrar_evento($id, 'CLAVE_NUEVA', 'Se generó una nueva clave; equipos liberados');
  return $clave;
}

function liberar_equipo(int $licencia, int $equipoId): void {
  db()->prepare('UPDATE equipos SET activo = 0 WHERE id = ? AND licencia_id = ?')->execute([$equipoId, $licencia]);
  registrar_evento($licencia, 'EQUIPO_LIBERADO', 'Equipo #' . $equipoId);
}

function equipos_activos(int $licencia): array {
  $r = db()->prepare('SELECT * FROM equipos WHERE licencia_id = ? ORDER BY activo DESC, ultimo_contacto DESC');
  $r->execute([$licencia]);
  return $r->fetchAll();
}

// ---------- API del programa de escritorio ----------
function licencia_por_clave(string $clave): array {
  $c = normalizar_clave($clave);
  if ($c === '') throw new ErrorLicencia('FORMATO', 'La clave debe tener el formato FX-XXXX-XXXX-XXXX.');
  $r = db()->prepare('SELECT * FROM licencias WHERE clave = ?');
  $r->execute([$c]);
  $l = $r->fetch();
  if (!$l) throw new ErrorLicencia('NO_EXISTE', 'La clave de licencia no existe. Revisa que la escribiste bien.');
  return $l;
}

function exigir_vigente(array $l): void {
  if ($l['estado'] === 'SUSPENDIDA') {
    throw new ErrorLicencia('SUSPENDIDA', 'Tu licencia está suspendida' . ($l['motivo'] ? ': ' . $l['motivo'] : '') . '. Contacta a soporte.');
  }
}

function validar_equipo(array $eq): array {
  $id = preg_replace('/[^a-f0-9]/', '', strtolower((string)($eq['id'] ?? '')));
  if (strlen($id) < 32 || strlen($id) > 64) throw new ErrorLicencia('EQUIPO', 'Identificador de equipo inválido.');
  return [
    'id' => $id,
    'nombre' => mb_substr(trim((string)($eq['nombre'] ?? '')), 0, 120),
    'so' => mb_substr(trim((string)($eq['so'] ?? '')), 0, 80),
    'version' => mb_substr(trim((string)($eq['version'] ?? '')), 0, 20),
  ];
}

function api_activar(string $clave, array $equipo): array {
  $l = licencia_por_clave($clave);
  exigir_vigente($l);
  $eq = validar_equipo($equipo);
  $d = db();
  $r = $d->prepare('SELECT * FROM equipos WHERE licencia_id = ? AND equipo = ?');
  $r->execute([$l['id'], $eq['id']]);
  $existente = $r->fetch();
  if (!$existente || !(int)$existente['activo']) {
    $r = $d->prepare('SELECT COUNT(*) FROM equipos WHERE licencia_id = ? AND activo = 1');
    $r->execute([$l['id']]);
    if ((int)$r->fetchColumn() >= (int)$l['equipos_max']) {
      throw new ErrorLicencia('LIMITE_EQUIPOS', 'Tu licencia ya está activada en ' . $l['equipos_max'] . ' equipo(s), que es el máximo de tu plan. Desactívala en el otro equipo o pide a soporte que lo libere.');
    }
  }
  if ($existente) {
    $d->prepare('UPDATE equipos SET activo = 1, nombre = ?, so = ?, version = ?, ip = ?, activado = ?, ultimo_contacto = ? WHERE id = ?')
      ->execute([$eq['nombre'], $eq['so'], $eq['version'], ip_cliente(), $existente['activo'] ? $existente['activado'] : ahora(), ahora(), $existente['id']]);
  } else {
    $d->prepare('INSERT INTO equipos (licencia_id, equipo, nombre, so, version, ip, activo, activado, ultimo_contacto) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)')
      ->execute([$l['id'], $eq['id'], $eq['nombre'], $eq['so'], $eq['version'], ip_cliente(), ahora(), ahora()]);
  }
  registrar_evento((int)$l['id'], 'ACTIVACION', ($eq['nombre'] ?: 'Equipo') . ' · v' . $eq['version']);
  return respuesta_token($l, $eq['id']);
}

function api_verificar(string $clave, array $equipo): array {
  $l = licencia_por_clave($clave);
  exigir_vigente($l);
  $eq = validar_equipo($equipo);
  $r = db()->prepare('SELECT * FROM equipos WHERE licencia_id = ? AND equipo = ? AND activo = 1');
  $r->execute([$l['id'], $eq['id']]);
  $e = $r->fetch();
  if (!$e) throw new ErrorLicencia('EQUIPO_LIBERADO', 'Este equipo ya no está autorizado para esta licencia. Vuelve a activarla.');
  db()->prepare('UPDATE equipos SET ultimo_contacto = ?, version = ?, ip = ? WHERE id = ?')->execute([ahora(), $eq['version'], ip_cliente(), $e['id']]);
  return respuesta_token($l, $eq['id']);
}

function api_desactivar(string $clave, array $equipo): array {
  $l = licencia_por_clave($clave);
  $eq = validar_equipo($equipo);
  db()->prepare('UPDATE equipos SET activo = 0 WHERE licencia_id = ? AND equipo = ?')->execute([$l['id'], $eq['id']]);
  registrar_evento((int)$l['id'], 'DESACTIVACION', ($eq['nombre'] ?: 'Equipo') . ' liberó la licencia');
  return ['ok' => true];
}

function respuesta_token(array $l, string $equipo): array {
  $est = estado_licencia($l);
  return [
    'ok' => true,
    'token' => firmar_token(payload_licencia($l, $equipo)),
    'estado' => $est['code'],
    'fechaServidor' => hoy(),
    'soporte' => ajuste('whatsapp', ''),
  ];
}
