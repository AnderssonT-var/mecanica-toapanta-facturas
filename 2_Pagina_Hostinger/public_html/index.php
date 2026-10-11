<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/app.php';

$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
if (!empty($_SESSION['client_id'])) {
    header('Location: history.php');
    exit;
}

pagina_inicio($config, 'Facturas', 'Consulta segura de facturas');
?>
<section class="panel">
<div class="icono-circulo"><?= icono('candado', 28) ?></div>
<h1>Tus facturas, privadas y a la mano</h1>
<p class="texto">Para ver tu factura, abre el enlace que te enviamos por WhatsApp. Ese enlace es personal: solo tú puedes ver tus documentos.</p>
<?php if ($error): ?><p class="alerta alerta-error"><?= e($error) ?></p><?php endif; ?>
<ol class="pasos">
<li><span>1</span>Busca nuestro mensaje en WhatsApp</li>
<li><span>2</span>Toca el enlace de tu factura</li>
<li><span>3</span>Mírala, descárgala o compártela</li>
</ol>
</section>

<?php if (enlace_whatsapp(ajustes($config)['whatsapp']) !== ''): ?>
<section class="panel panel-compacto">
<h2>¿No encuentras el mensaje o el enlace venció?</h2>
<p class="texto">Escríbenos y te lo reenviamos en un momento.</p>
<?php bloque_contacto($config); ?>
</section>
<?php endif; ?>
<?php pagina_fin($config);
