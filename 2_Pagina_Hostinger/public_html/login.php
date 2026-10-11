<?php
// La consulta escribiendo solo el teléfono quedó deshabilitada: permitía ver
// facturas ajenas. Ahora se entra únicamente con el enlace personal de WhatsApp.
header('Location: index.php', true, 303);
exit;
