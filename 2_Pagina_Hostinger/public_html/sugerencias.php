<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/app.php';

$errores = [];
$enviado = false;
$nombre = '';
$telefono = !empty($_SESSION['phone']) ? telefono_local((string)$_SESSION['phone']) : '';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $telefono = trim((string)($_POST['telefono'] ?? ''));
    $mensaje = trim((string)($_POST['mensaje'] ?? ''));
    $tel = normalize_phone($telefono);

    if (!csrf_valido()) {
        $errores[] = 'La página caducó. Vuelve a intentarlo.';
    } elseif (trim((string)($_POST['sitio_web'] ?? '')) !== '') {
        $enviado = true; // trampa para robots: se finge éxito sin guardar
    } else {
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) $errores[] = 'Escribe tu nombre.';
        if (!is_ecuadorian_mobile($tel)) $errores[] = 'Escribe un celular válido, por ejemplo 0959133066.';
        if (mb_strlen($mensaje) < 5) $errores[] = 'Cuéntanos un poco más en tu reseña o sugerencia.';
        if (mb_strlen($mensaje) > 2000) $errores[] = 'El mensaje es muy largo (máximo 2000 caracteres).';

        if (!$errores) {
            $pdo = db($config);
            app_schema($pdo);
            $recientes = $pdo->prepare('SELECT COUNT(*) FROM feedback WHERE ip_hash = :ip AND created_at > (NOW() - INTERVAL 1 HOUR)');
            $recientes->execute(['ip' => ip_hash()]);
            if ((int)$recientes->fetchColumn() >= 5) {
                $errores[] = 'Recibimos varios mensajes seguidos. Intenta de nuevo en un rato.';
            } else {
                $ins = $pdo->prepare('INSERT INTO feedback (name, phone, message, ip_hash) VALUES (:n, :t, :m, :ip)');
                $ins->execute(['n' => $nombre, 't' => $tel, 'm' => $mensaje, 'ip' => ip_hash()]);
                $enviado = true;
                $nombre = $mensaje = '';
            }
        }
    }
}

pagina_inicio($config, 'Sugerencias', 'Tu opinión nos ayuda a mejorar');
?>
<section class="panel">
<?php if ($enviado): ?>
<div class="icono-circulo"><?= icono('mensaje', 28) ?></div>
<h1>¡Gracias por escribirnos!</h1>
<p class="texto">Recibimos tu mensaje. Lo leemos todo y, si hace falta, te contactamos a tu celular.</p>
<a class="boton boton-principal" href="<?= !empty($_SESSION['client_id']) ? 'history.php' : 'index.php' ?>">Volver</a>
<?php else: ?>
<h1>Reseñas y sugerencias</h1>
<p class="texto">¿Cómo te fue con nosotros? ¿Qué podemos mejorar? Te leemos.</p>
<?php foreach ($errores as $err): ?><p class="alerta alerta-error"><?= e($err) ?></p><?php endforeach; ?>
<form method="post" action="sugerencias.php" class="formulario" novalidate>
<?= csrf_campo() ?>
<div class="campo-oculto" aria-hidden="true"><label for="sitio_web">No llenar</label><input id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off"></div>
<label for="nombre">Nombre</label>
<input id="nombre" name="nombre" maxlength="120" autocomplete="name" required value="<?= e($nombre) ?>">
<label for="telefono">Celular</label>
<input id="telefono" name="telefono" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="0959133066" required value="<?= e($telefono) ?>">
<label for="mensaje">Tu reseña o sugerencia</label>
<textarea id="mensaje" name="mensaje" rows="5" maxlength="2000" required><?= e($mensaje) ?></textarea>
<button type="submit" class="boton boton-principal boton-grande">Enviar</button>
</form>
<?php endif; ?>
</section>
<?php if (!$enviado): ?><p class="centrado"><a class="enlace-suave" href="<?= !empty($_SESSION['client_id']) ? 'history.php' : 'index.php' ?>">Volver</a></p><?php endif; ?>
<?php pagina_fin($config);
