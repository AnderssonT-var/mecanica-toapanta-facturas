<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/app.php';
if (empty($_SESSION['client_id'])) { header('Location: index.php'); exit; }

$stmt = db($config)->prepare('SELECT id, original_name, storage_name, invoice_date, expires_at FROM invoices
    WHERE client_id = :client_id AND expires_at >= CURDATE() ORDER BY invoice_date DESC, id DESC');
$stmt->execute(['client_id' => $_SESSION['client_id']]);
$facturas = $stmt->fetchAll();
$almacen = rtrim($config['private_storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$a = ajustes($config);
$resenas = url_segura($a['google_review_url']);

function botones_factura(array $f, bool $grandes): void {
    $id = (int)$f['id'];
    $nombre = 'Factura ' . $f['invoice_date'] . '.pdf';
    ?>
<?php if ($grandes): ?>
<a class="boton boton-principal boton-grande" href="download.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= icono('ver', 22) ?>Ver factura</a>
<div class="dos-botones">
<a class="boton boton-borde" href="download.php?id=<?= $id ?>&amp;dl=1"><?= icono('descargar') ?>Descargar</a>
<button type="button" class="boton boton-borde" data-compartir="<?= $id ?>" data-nombre="<?= e($nombre) ?>"><?= icono('compartir') ?>Compartir</button>
</div>
<?php else: ?>
<div class="tres-botones">
<a class="boton boton-principal" href="download.php?id=<?= $id ?>" target="_blank" rel="noopener">Ver</a>
<a class="boton boton-borde" href="download.php?id=<?= $id ?>&amp;dl=1">Descargar</a>
<button type="button" class="boton boton-borde" data-compartir="<?= $id ?>" data-nombre="<?= e($nombre) ?>">Compartir</button>
</div>
<?php endif;
}

pagina_inicio($config, 'Mis facturas', 'Hola, estas son tus facturas');
?>
<?php if (!$facturas): ?>
<section class="panel">
<h1>No tienes facturas vigentes</h1>
<p class="texto">Las facturas se retiran 30 días después de emitidas. Si necesitas una copia, escríbenos.</p>
<?php bloque_contacto($config, 'Pedir una copia por WhatsApp'); ?>
</section>
<?php else: $f = $facturas[0]; $ruta = $almacen . $f['storage_name']; ?>
<section class="panel panel-destacado">
<div class="fila-entre">
<span class="etiqueta">Factura más reciente</span>
<?php if ($f['invoice_date'] >= date('Y-m-d', strtotime('-3 days'))): ?><span class="pildora">Nueva</span><?php endif; ?>
</div>
<h1>Factura del <?= e(fecha_larga($f['invoice_date'])) ?></h1>
<p class="muted small">PDF<?= is_file($ruta) ? ' · ' . e(tamano_legible((int)filesize($ruta))) : '' ?></p>
<p class="alerta alerta-aviso"><?= icono('reloj', 18) ?>Disponible hasta el <?= e(fecha_corta($f['expires_at'])) ?>. Descárgala para guardarla.</p>
<?php botones_factura($f, true); ?>
</section>

<?php if (count($facturas) > 1): ?>
<section>
<h2 class="titulo-seccion">Facturas anteriores</h2>
<div class="lista">
<?php foreach (array_slice($facturas, 1) as $f): ?>
<article class="tarjeta">
<div>
<strong>Factura del <?= e(fecha_larga($f['invoice_date'])) ?></strong>
<span class="muted small">Disponible hasta el <?= e(fecha_corta($f['expires_at'])) ?></span>
</div>
<?php botones_factura($f, false); ?>
</article>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($resenas): ?>
<section class="panel panel-centrado">
<div class="estrellas" aria-hidden="true"><?= str_repeat(icono('estrella', 26), 5) ?></div>
<h2>¿Te atendimos bien?</h2>
<p class="texto">Tu opinión ayuda a que más personas confíen en nuestro taller.</p>
<a class="boton boton-oscuro" href="<?= e($resenas) ?>" target="_blank" rel="noopener">Califícanos en Google</a>
</section>
<?php endif; ?>

<?php bloque_contacto($config); ?>
<p class="centrado"><a class="enlace-suave" href="logout.php">Cerrar sesión</a></p>

<div class="aviso-flotante" id="aviso" role="status" hidden></div>
<script>
(function () {
  var aviso = document.getElementById('aviso');
  function avisar(texto) {
    aviso.textContent = texto; aviso.hidden = false;
    clearTimeout(avisar.t); avisar.t = setTimeout(function () { aviso.hidden = true; }, 4000);
  }
  document.querySelectorAll('[data-compartir]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var id = boton.getAttribute('data-compartir');
      var nombre = boton.getAttribute('data-nombre') || 'factura.pdf';
      if (!navigator.share || !window.File) {
        avisar('Tu teléfono no permite compartir desde aquí. Descarga la factura y compártela desde tus archivos.');
        return;
      }
      boton.disabled = true;
      fetch('download.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.blob(); })
        .then(function (blob) {
          var archivo = new File([blob], nombre, { type: 'application/pdf' });
          if (navigator.canShare && !navigator.canShare({ files: [archivo] })) throw new Error('no-files');
          return navigator.share({ files: [archivo], title: 'Factura' });
        })
        .catch(function (e) {
          if (e && e.name === 'AbortError') return;
          avisar('No se pudo compartir. Descarga la factura y compártela desde tus archivos.');
        })
        .finally(function () { boton.disabled = false; });
    });
  });
})();
</script>
<?php pagina_fin($config);
