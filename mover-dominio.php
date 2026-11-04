<?php
// Mudanza única del portal de mecanicatoapantavelez.com a facmit.com (mismo servidor y misma base de datos).
// Copia lo que no está en el repositorio: config.php (base de datos y llave), respaldos de la nube y actualizaciones.
// Solo responde a un POST con el token correcto (aquí se guarda únicamente su huella) y se borra al terminar.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
const HUELLA = '78f5970279d62664e06ba18f0b5140bf74b9fb44fa366881792869883132349e';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(HUELLA, hash('sha256', (string)($_POST['token'] ?? '')))) { http_response_code(404); exit; }
$origen = __DIR__;
$destino = dirname(__DIR__, 3) . '/facmit.com/public_html/licencias';
if (!preg_match('#/domains/mecanicatoapantavelez\.com/public_html/licencias$#', $origen) || !is_dir($destino)) { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'rutas', 'destino' => is_dir($destino)]); exit; }
$copiados = 0; $omitidos = 0;
$copiar = function (string $a, string $b) use (&$copiar, &$copiados, &$omitidos): void {
  if (is_dir($a)) {
    if (!is_dir($b)) mkdir($b, 0750, true);
    foreach (scandir($a) ?: [] as $n) if ($n !== '.' && $n !== '..') $copiar($a . '/' . $n, $b . '/' . $n);
    return;
  }
  if (is_file($b) && filesize($b) === filesize($a)) { $omitidos++; return; }
  if (copy($a, $b)) $copiados++;
};
$res = [];
if (!is_file($destino . '/config.php')) { $res['config'] = copy($origen . '/config.php', $destino . '/config.php'); @chmod($destino . '/config.php', 0640); } else $res['config'] = 'ya existía';
foreach (['nube/datos', 'actualizaciones'] as $dir) if (is_dir($origen . '/' . $dir)) $copiar($origen . '/' . $dir, $destino . '/' . $dir);
$res += ['ok' => true, 'copiados' => $copiados, 'omitidos' => $omitidos];
// Los respaldos que suban los programas aún no actualizados también van a la carpeta nueva.
$viejo = $origen . '/nube/datos';
if (is_dir($viejo) && !is_link($viejo) && rename($viejo, $viejo . '-anterior-' . date('Ymd'))) $res['enlace_respaldos'] = symlink($destino . '/nube/datos', $viejo);
@unlink(__FILE__);
echo json_encode($res);
