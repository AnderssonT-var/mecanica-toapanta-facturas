<?php
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }

// Crea las tablas del portal (MySQL/MariaDB en Hostinger; SQLite solo para pruebas locales).
function crear_esquema(PDO $pdo, string $driver): void {
  $ai = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
  $fin = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $ref = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
  $sql = [
    "CREATE TABLE IF NOT EXISTS admins (
      id $ai, usuario VARCHAR(40) NOT NULL UNIQUE, nombre VARCHAR(120) NOT NULL DEFAULT '', email VARCHAR(160) NOT NULL DEFAULT '',
      hash VARCHAR(255) NOT NULL, activo TINYINT NOT NULL DEFAULT 1, creado DATETIME NOT NULL, ultimo_acceso DATETIME NULL)$fin",
    "CREATE TABLE IF NOT EXISTS licencias (
      id $ai, clave VARCHAR(20) NOT NULL UNIQUE, cliente VARCHAR(200) NOT NULL, ruc CHAR(13) NOT NULL, rucs TEXT NULL,
      email VARCHAR(160) NOT NULL DEFAULT '', telefono VARCHAR(40) NOT NULL DEFAULT '', plan VARCHAR(10) NOT NULL,
      inicio DATE NOT NULL, vence DATE NOT NULL, gracia INT NOT NULL DEFAULT 3, empresas INT NOT NULL DEFAULT 1,
      equipos_max INT NOT NULL DEFAULT 1, usuarios INT NOT NULL DEFAULT 1, puntos INT NOT NULL DEFAULT 1,
      precio DECIMAL(10,2) NOT NULL DEFAULT 0, periodo VARCHAR(12) NOT NULL DEFAULT 'MENSUAL',
      estado VARCHAR(12) NOT NULL DEFAULT 'ACTIVA', motivo VARCHAR(255) NOT NULL DEFAULT '', notas TEXT NULL,
      creado DATETIME NOT NULL, actualizado DATETIME NOT NULL)$fin",
    "CREATE TABLE IF NOT EXISTS equipos (
      id $ai, licencia_id $ref NOT NULL, equipo VARCHAR(64) NOT NULL, nombre VARCHAR(120) NOT NULL DEFAULT '',
      so VARCHAR(80) NOT NULL DEFAULT '', version VARCHAR(20) NOT NULL DEFAULT '', ip VARCHAR(45) NOT NULL DEFAULT '',
      activo TINYINT NOT NULL DEFAULT 1, activado DATETIME NOT NULL, ultimo_contacto DATETIME NOT NULL,
      UNIQUE (licencia_id, equipo))$fin",
    "CREATE TABLE IF NOT EXISTS pagos (
      id $ai, licencia_id $ref NOT NULL, fecha DATE NOT NULL, monto DECIMAL(10,2) NOT NULL DEFAULT 0, meses INT NOT NULL DEFAULT 0,
      metodo VARCHAR(40) NOT NULL DEFAULT '', referencia VARCHAR(120) NOT NULL DEFAULT '', nota VARCHAR(255) NOT NULL DEFAULT '',
      vence_anterior DATE NULL, vence_nuevo DATE NULL, admin_id $ref NULL, creado DATETIME NOT NULL)$fin",
    "CREATE TABLE IF NOT EXISTS eventos (
      id $ai, licencia_id $ref NULL, tipo VARCHAR(30) NOT NULL, detalle VARCHAR(500) NOT NULL DEFAULT '',
      ip VARCHAR(45) NOT NULL DEFAULT '', creado DATETIME NOT NULL)$fin",
    "CREATE TABLE IF NOT EXISTS intentos (
      id $ai, ip VARCHAR(45) NOT NULL, tipo VARCHAR(20) NOT NULL, creado DATETIME NOT NULL)$fin",
    "CREATE TABLE IF NOT EXISTS ajustes (k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT NOT NULL)$fin",
  ];
  foreach ($sql as $s) $pdo->exec($s);
  foreach ([
    'CREATE INDEX ix_eventos_lic ON eventos (licencia_id, creado)',
    'CREATE INDEX ix_pagos_lic ON pagos (licencia_id, fecha)',
    'CREATE INDEX ix_intentos ON intentos (ip, tipo, creado)',
    'CREATE INDEX ix_lic_vence ON licencias (vence)',
  ] as $s) {
    try { $pdo->exec($s); } catch (PDOException $ex) { /* ya existe */ }
  }
}
