<?php
// Directorio global de Facmit: identificación (cédula o RUC) → nombre y dirección, compartido por TODAS las licencias.
// Sirve para que, al escribir una cédula o RUC, aparezca el nombre aunque ese negocio nunca haya facturado a esa persona.
//
// Qué guarda y de dónde (las tres vías, todas legales):
//   1) FACTURAS: el nombre y la dirección que cada negocio ya tiene de su cliente (se engancha en nube_guardar 'clientes').
//   2) SRI por RUC: dato público del padrón de contribuyentes (lo aporta el programa tras consultar al SRI).
//   3) Proveedor (EcuadorAPI/Datacil): NO se archiva aquí (sus términos lo prohíben); solo entra cuando el negocio factura.
//
// Privacidad: cada negocio solo ve SUS clientes (correo, teléfono, etc. siguen en su propia nube). El directorio global
// NO se puede listar ni recorrer: solo responde "¿nombre de ESTA identificación exacta?". Guarda únicamente
// identificación, nombre y dirección (nada de correo, teléfono ni montos).
if (!defined('FX_PORTAL')) exit;

function directorio_esquema(): void {
  static $listo = false;
  if ($listo) return;
  if (fx_config()['db']['driver'] === 'sqlite') {
    db()->exec("CREATE TABLE IF NOT EXISTS directorio_global (
      identificacion TEXT PRIMARY KEY, tipo TEXT NOT NULL, nombre TEXT NOT NULL, direccion TEXT NOT NULL DEFAULT '',
      fuente TEXT NOT NULL DEFAULT '', creado TEXT NOT NULL, actualizado TEXT NOT NULL)");
  } else {
    db()->exec("CREATE TABLE IF NOT EXISTS directorio_global (
      identificacion VARCHAR(13) NOT NULL PRIMARY KEY, tipo CHAR(3) NOT NULL, nombre VARCHAR(300) NOT NULL,
      direccion VARCHAR(400) NOT NULL DEFAULT '', fuente VARCHAR(12) NOT NULL DEFAULT '',
      creado DATETIME NOT NULL, actualizado DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  $listo = true;
}

// Deja una identificación en su forma canónica y dice de qué tipo es. Solo cédula y RUC entran al directorio
// (son identificadores verificables); consumidor final, pasaporte y basura quedan fuera. Devuelve [id, tipo] o null.
function directorio_normid(string $id, string $tipo = ''): ?array {
  $n = preg_replace('/\D/', '', $id);
  if ($n === '' || $n === '9999999999999' || preg_match('/^9+$/', $n)) return null;
  if (strlen($n) === 13 && validar_ruc($n)) return [$n, 'RUC'];
  if (strlen($n) === 10 && validar_cedula($n)) return [$n, 'CED'];
  return null;
}

function directorio_limpiar_nombre(string $s): string {
  $s = preg_replace('/\s+/u', ' ', trim($s));
  return mb_substr($s, 0, 300);
}

// Guarda o mejora una entrada. Nunca empeora lo que ya hay: conserva el nombre existente si el nuevo llega vacío,
// y rellena la dirección solo si estaba vacía. Pensado para llamarse mucho (cada factura), así que es barato y tolerante.
function directorio_guardar(string $identificacion, string $nombre, string $direccion = '', string $fuente = ''): bool {
  $norm = directorio_normid($identificacion);
  if (!$norm) return false;
  [$id, $tipo] = $norm;
  $nombre = directorio_limpiar_nombre($nombre);
  if (mb_strlen($nombre) < 3) return false;
  $direccion = mb_substr(preg_replace('/\s+/u', ' ', trim($direccion)), 0, 400);
  $fuente = mb_substr($fuente, 0, 12);
  try {
    directorio_esquema();
    $r = db()->prepare('SELECT nombre, direccion FROM directorio_global WHERE identificacion = ?');
    $r->execute([$id]);
    $prev = $r->fetch();
    if (!$prev) {
      db()->prepare('INSERT INTO directorio_global (identificacion, tipo, nombre, direccion, fuente, creado, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $tipo, mb_strtoupper($nombre, 'UTF-8'), $direccion, $fuente, ahora(), ahora()]);
      return true;
    }
    // Ya existe: completa solo lo que falta, sin pisar datos buenos.
    $dir = ($prev['direccion'] === '' && $direccion !== '') ? $direccion : $prev['direccion'];
    if ($dir === $prev['direccion']) {
      db()->prepare('UPDATE directorio_global SET actualizado = ? WHERE identificacion = ?')->execute([ahora(), $id]);
    } else {
      db()->prepare('UPDATE directorio_global SET direccion = ?, actualizado = ? WHERE identificacion = ?')->execute([$dir, ahora(), $id]);
    }
    return true;
  } catch (Throwable $e) {
    return false; // el directorio es un extra: si falla, la factura sigue su curso
  }
}

// Consulta por identificación EXACTA. Devuelve ['nombre','direccion','tipo'] o null. No permite buscar por nombre.
function directorio_consultar(string $identificacion): ?array {
  $norm = directorio_normid($identificacion);
  if (!$norm) return null;
  directorio_esquema();
  $r = db()->prepare('SELECT nombre, direccion, tipo FROM directorio_global WHERE identificacion = ?');
  $r->execute([$norm[0]]);
  $g = $r->fetch();
  return $g ? ['nombre' => (string)$g['nombre'], 'direccion' => (string)$g['direccion'], 'tipo' => (string)$g['tipo']] : null;
}

// Cuántas personas/negocios hay en el directorio (para el contador del portal).
function directorio_stats(): array {
  directorio_esquema();
  $total = (int)db()->query('SELECT COUNT(*) FROM directorio_global')->fetchColumn();
  $cedulas = (int)db()->query("SELECT COUNT(*) FROM directorio_global WHERE tipo = 'CED'")->fetchColumn();
  $rucs = (int)db()->query("SELECT COUNT(*) FROM directorio_global WHERE tipo = 'RUC'")->fetchColumn();
  $hoy = (int)db()->query("SELECT COUNT(*) FROM directorio_global WHERE creado >= '" . date('Y-m-d') . " 00:00:00'")->fetchColumn();
  return ['total' => $total, 'cedulas' => $cedulas, 'rucs' => $rucs, 'hoy' => $hoy];
}
