<?php
require __DIR__ . '/_lib.php';

// ---------- acceso ----------
if (!admin_logueado()) {
    $error = '';
    $bloqueo = admin_bloqueado($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($bloqueo > 0) {
            $error = "Demasiados intentos. Espera $bloqueo minuto(s).";
        } elseif (!csrf_valido()) {
            $error = 'La página caducó. Vuelve a intentarlo.';
        } else {
            $hash = admin_hash_actual($pdo);
            $ok = $hash !== '' && password_verify((string)($_POST['clave'] ?? ''), $hash);
            admin_intento($pdo, $ok);
            if ($ok) {
                session_regenerate_id(true);
                $_SESSION['admin_desde'] = $_SESSION['admin_uso'] = time();
                header('Location: index.php');
                exit;
            }
            usleep(800000);
            $bloqueo = admin_bloqueado($pdo);
            $error = $bloqueo > 0 ? "Demasiados intentos. Espera $bloqueo minuto(s)." : 'Contraseña incorrecta.';
        }
    }
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar | Admin</title>
<link rel="icon" href="../assets/icono.png">
<link rel="stylesheet" href="../assets/app.css?v=2">
</head>
<body class="admin">
<main class="login-admin">
<section class="panel">
<div class="brand"><div class="brand-mark">MTV</div><div><div class="brand-name">Panel del taller</div><div class="muted small">Solo personal autorizado</div></div></div>
<?php if ($error): ?><p class="alerta alerta-error"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="formulario">
<?= csrf_campo() ?>
<label for="clave">Contraseña</label>
<input id="clave" name="clave" type="password" autocomplete="current-password" required autofocus>
<button type="submit" class="boton boton-principal boton-grande">Entrar</button>
</form>
</section>
</main>
</body>
</html>
<?php
    exit;
}

// ---------- resumen ----------
$clientes = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$vigentes = (int)$pdo->query('SELECT COUNT(*) FROM invoices WHERE expires_at >= CURDATE()')->fetchColumn();
$hoy = (int)$pdo->query('SELECT COUNT(*) FROM invoices WHERE invoice_date = CURDATE()')->fetchColumn();
$sinLeer = sugerencias_sin_leer($pdo);
$ultimas = $pdo->query('SELECT i.id, i.invoice_date, i.expires_at, c.phone FROM invoices i
    JOIN clients c ON c.id = i.client_id ORDER BY i.id DESC LIMIT 8')->fetchAll();
$sugerencias = $pdo->query('SELECT * FROM feedback ORDER BY id DESC LIMIT 4')->fetchAll();
$a = ajustes($config);
$faltan = [];
if (enlace_whatsapp($a['whatsapp']) === '') $faltan[] = 'el WhatsApp de contacto';
if (url_segura($a['google_review_url']) === '') $faltan[] = 'el enlace de reseñas de Google';
if (!sucursales($config)) $faltan[] = 'al menos una sucursal con su Google Maps';

admin_inicio($pdo, 'Resumen', 'index');
?>
<h1>Resumen</h1>
<?php if (!$pdo->query("SELECT COUNT(*) FROM site_settings WHERE skey = 'admin_password_hash'")->fetchColumn()): ?>
<p class="alerta alerta-error">Estás usando la contraseña inicial. <a href="configuracion.php#clave">Cámbiala ahora</a> por una que solo tú conozcas.</p>
<?php endif; ?>
<?php if ($faltan): ?>
<p class="alerta alerta-aviso">Para completar la página falta: <?= e(implode(', ', $faltan)) ?>. <a href="configuracion.php">Ir a Configuración</a></p>
<?php endif; ?>

<div class="cifras">
<a class="cifra" href="clientes.php"><b><?= $clientes ?></b><span class="muted">Clientes</span></a>
<a class="cifra" href="clientes.php"><b><?= $vigentes ?></b><span class="muted">Facturas vigentes</span></a>
<div class="cifra"><b><?= $hoy ?></b><span class="muted">Facturas subidas hoy</span></div>
<a class="cifra" href="sugerencias.php?ver=nuevas"><b><?= $sinLeer ?></b><span class="muted">Sugerencias sin leer</span></a>
</div>

<section class="caja">
<div class="fila-entre"><h2>Últimas facturas subidas</h2><a href="clientes.php">Ver todos los clientes</a></div>
<?php if (!$ultimas): ?><p class="muted">Todavía no hay facturas.</p><?php else: ?>
<div class="tabla-envoltura"><table>
<thead><tr><th>Cliente</th><th>Fecha</th><th>Estado</th><th></th></tr></thead>
<tbody>
<?php foreach ($ultimas as $f): $vig = $f['expires_at'] >= date('Y-m-d'); ?>
<tr>
<td><a href="cliente.php?tel=<?= e($f['phone']) ?>"><?= e(telefono_local($f['phone'])) ?></a></td>
<td><?= e(fecha_larga($f['invoice_date'])) ?></td>
<td><span class="estado <?= $vig ? 'estado-ok' : 'estado-vencida' ?>"><?= $vig ? 'Vigente' : 'Vencida' ?></span></td>
<td class="acciones"><a class="boton boton-claro boton-chico" href="factura.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">Ver PDF</a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>

<section class="caja">
<div class="fila-entre"><h2>Últimas reseñas y sugerencias</h2><a href="sugerencias.php">Ver todas</a></div>
<?php if (!$sugerencias): ?><p class="muted">Aún no hay mensajes.</p><?php else: ?>
<div class="lista">
<?php foreach ($sugerencias as $s): ?>
<div class="tarjeta">
<div class="fila-entre"><strong><?= e($s['name']) ?></strong><span class="muted small"><?= e(fecha_larga($s['created_at'])) ?></span></div>
<p class="mensaje-sugerencia"><?= e(mb_strimwidth($s['message'], 0, 220, '…')) ?></p>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
<?php admin_fin();
