<?php
/**
 * EPGWeb — Configuración base.
 *
 * No edita valores aquí salvo las constantes; la configuración editable
 * del panel vive en la tabla `settings` de la base de datos.
 */

if (!defined('EPGWEB')) { define('EPGWEB', true); }

define('EPGW_VERSION', '1.0.0');

// Rutas absolutas de la aplicación
define('EPGW_ROOT',    dirname(__DIR__));            // raíz del proyecto
define('EPGW_APP',     __DIR__);                     // /app
define('EPGW_PUBLIC',  EPGW_ROOT . '/public');       // /public  (raíz web)
define('EPGW_DATA',    EPGW_ROOT . '/data');         // /data    (no debe ser pública)

// Fuente EPG por defecto (servidor Astra del cliente)
define('EPGW_DEFAULT_SOURCE_URL', 'http://38.226.161.148:5000/');
define('EPGW_DEFAULT_SOURCE_NAME', 'Astra — Guía principal');

// Zona horaria por defecto del servicio (Managua, UTC-6)
define('EPGW_DEFAULT_TZ', 'America/Managua');

// Duración de la sesión del panel (segundos de inactividad)
define('EPGW_SESSION_LIFETIME', 8 * 3600);

// Límites de exportación por API
define('EPGW_MAX_EXPORT_DAYS', 14);
define('EPGW_DEFAULT_EXPORT_DAYS', 3);

// Tiempo máximo (segundos) para una importación vía web
define('EPGW_IMPORT_TIME_LIMIT', 600);

/**
 * Garantiza que existan los directorios de datos (best effort).
 */
function epgw_ensure_dirs() {
    foreach (array(EPGW_DATA, EPGW_DATA . '/cache', EPGW_DATA . '/sessions', EPGW_DATA . '/uploads') as $d) {
        if (!is_dir($d)) { @mkdir($d, 0775, true); }
    }
}
epgw_ensure_dirs();
