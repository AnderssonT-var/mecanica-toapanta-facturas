<?php
// PLANTILLA de config.php (el archivo real NO va en este zip porque tiene las contraseñas).
// Solo se usa para instalar el portal desde cero: copia este archivo a public_html/config.php
// y completa los valores. En Hostinger el config.php real ya existe: no lo reemplaces.
return [
    'db' => [
        'host' => '127.0.0.1',            // en Hostinger la base de datos está en el mismo servidor
        'name' => 'NOMBRE_DE_LA_BASE',
        'user' => 'USUARIO_DE_LA_BASE',
        'password' => 'CONTRASEÑA_DE_LA_BASE',
        'charset' => 'utf8mb4',
    ],
    'session_name' => 'mtv_session',
    // Carpeta FUERA de public_html donde se guardan los PDF (en Hostinger: /home/<usuario>/domains/<dominio>/private_storage)
    'private_storage' => '/RUTA/ABSOLUTA/private_storage',
    'retention_days' => 30,
    'portal_url' => 'https://mecanicatoapantavelez.com',
    // sha256 de la clave de subida que usa el programa de Windows (el programa la genera en Configuración)
    'upload_key_hash' => 'HASH_SHA256_DE_LA_CLAVE',
];
