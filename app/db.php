<?php
/**
 * EPGWeb — Acceso a base de datos (SQLite vía PDO) y esquema.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit('EPGWeb: acceso directo no permitido'); }

/** @var PDO|null Instancia PDO compartida por petición */
$GLOBALS['epgw_pdo'] = null;

/**
 * Devuelve la conexión PDO (singleton). Lanza Exception si no se puede abrir.
 * @return PDO
 */
function epg_db() {
    if ($GLOBALS['epgw_pdo'] instanceof PDO) { return $GLOBALS['epgw_pdo']; }
    $file = EPGW_DATA . '/epgweb.sqlite';
    $fresh = !file_exists($file);
    $pdo = new PDO('sqlite:' . $file, null, null, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
    $pdo->exec('PRAGMA busy_timeout = 8000');
    try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (Exception $e) { /* host sin WAL */ }
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $GLOBALS['epgw_pdo'] = $pdo;
    if ($fresh) { epg_db_install($pdo); }
    return $pdo;
}

/** Ruta del archivo de base de datos */
function epg_db_file() { return EPGW_DATA . '/epgweb.sqlite'; }

/**
 * Crea/actualiza el esquema (idempotente).
 */
function epg_db_install(PDO $db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);
CREATE TABLE IF NOT EXISTS sources (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    url             TEXT NOT NULL DEFAULT '',
    enabled         INTEGER NOT NULL DEFAULT 1,
    last_refresh    INTEGER,
    last_status     TEXT NOT NULL DEFAULT '',
    last_message    TEXT NOT NULL DEFAULT '',
    last_channels   INTEGER NOT NULL DEFAULT 0,
    last_programmes INTEGER NOT NULL DEFAULT 0,
    created_at      INTEGER
);
CREATE TABLE IF NOT EXISTS channels (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id       INTEGER NOT NULL,
    xmltv_id        TEXT NOT NULL,
    display_name    TEXT NOT NULL,
    icon            TEXT NOT NULL DEFAULT '',
    url             TEXT NOT NULL DEFAULT '',
    enabled         INTEGER NOT NULL DEFAULT 1,
    programme_count INTEGER NOT NULL DEFAULT 0,
    first_start     INTEGER,
    last_stop       INTEGER,
    UNIQUE (source_id, xmltv_id)
);
CREATE INDEX IF NOT EXISTS idx_channels_source ON channels(source_id);
CREATE TABLE IF NOT EXISTS programmes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id  INTEGER NOT NULL,
    xmltv_id   TEXT NOT NULL,
    starts_at  INTEGER NOT NULL,
    ends_at    INTEGER NOT NULL,
    title      TEXT NOT NULL,
    sub_title  TEXT NOT NULL DEFAULT '',
    descr      TEXT NOT NULL DEFAULT '',
    category   TEXT NOT NULL DEFAULT '',
    country    TEXT NOT NULL DEFAULT '',
    episode    TEXT NOT NULL DEFAULT '',
    icon       TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_prog_chan_time ON programmes(source_id, xmltv_id, starts_at);
CREATE INDEX IF NOT EXISTS idx_prog_time ON programmes(starts_at);
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'user',
    api_key       TEXT NOT NULL UNIQUE,
    access_mode   TEXT NOT NULL DEFAULT 'all',
    is_active     INTEGER NOT NULL DEFAULT 1,
    expires_at    INTEGER,
    notes         TEXT NOT NULL DEFAULT '',
    created_at    INTEGER,
    last_login    INTEGER,
    last_use      INTEGER
);
CREATE TABLE IF NOT EXISTS user_channels (
    user_id    INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    UNIQUE (user_id, channel_id)
);
CREATE TABLE IF NOT EXISTS refreshes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id   INTEGER,
    started_at  INTEGER,
    finished_at INTEGER,
    status      TEXT NOT NULL DEFAULT '',
    channels    INTEGER NOT NULL DEFAULT 0,
    programmes  INTEGER NOT NULL DEFAULT 0,
    message     TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS accesses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    endpoint   TEXT NOT NULL DEFAULT '',
    fmt        TEXT NOT NULL DEFAULT '',
    status     INTEGER NOT NULL DEFAULT 200,
    bytes      INTEGER NOT NULL DEFAULT 0,
    ip         TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    created_at INTEGER
);
CREATE INDEX IF NOT EXISTS idx_acc_created ON accesses(created_at);
SQL
    );
}

/** ¿La aplicación ya está instalada (existe el usuario administrador)? */
function epg_installed() {
    try {
        $db = epg_db();
        $n = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        return $n > 0;
    } catch (Exception $e) { return false; }
}

/* ------------------------------------------------------------------ */
/* Ajustes (settings) con caché por petición                           */
/* ------------------------------------------------------------------ */

$GLOBALS['epgw_settings_cache'] = array();

/** Lee un ajuste (o $default si no existe). */
function epg_setting($key, $default = null) {
    if (array_key_exists($key, $GLOBALS['epgw_settings_cache'])) {
        return $GLOBALS['epgw_settings_cache'][$key];
    }
    try {
        $db = epg_db();
        $st = $db->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute(array($key));
        $v = $st->fetchColumn();
        if ($v === false) { $v = $default; }
    } catch (Exception $e) { $v = $default; }
    $GLOBALS['epgw_settings_cache'][$key] = $v;
    return $v;
}

/** Guarda un ajuste. */
function epg_setting_set($key, $value) {
    $db = epg_db();
    $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                  ON CONFLICT(key) DO UPDATE SET value = excluded.value')
       ->execute(array($key, (string)$value));
    $GLOBALS['epgw_settings_cache'][$key] = $value;
}

/** Zona horaria configurada del sitio. */
function epg_site_tz() {
    $tz = epg_setting('timezone', EPGW_DEFAULT_TZ);
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) { $tz = EPGW_DEFAULT_TZ; }
    return $tz;
}
