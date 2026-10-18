<?php
/**
 * EPGWeb — Bootstrap común (panel y API).
 */

if (!defined('EPGWEB')) { define('EPGWEB', true); }

// Aislamiento por petición: en runtimes persistentes (worker mode, PHP-WASM)
// los recursos de la petición anterior podrían seguir vivos.
unset($GLOBALS['epgw_pdo'], $GLOBALS['epgw_settings_cache'], $GLOBALS['epgw_auth_user_cache']);

require_once __DIR__ . '/config.php';

// Errores: registrar pero no mostrar al usuario final
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/importer.php';
require_once __DIR__ . '/exporter.php';

// Zona horaria del sitio para todo date()/DateTime del panel
try {
    date_default_timezone_set(epg_site_tz());
} catch (Exception $e) {
    date_default_timezone_set(EPGW_DEFAULT_TZ);
}

// Cabeceras de seguridad básicas
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Powered-By: EPGWeb');
}
