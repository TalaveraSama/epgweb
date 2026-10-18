<?php
/**
 * EPGWeb — Endpoint público de la guía para reproductores y clientes.
 *
 * Formas de uso:
 *   api.php?k=CLAVE                          → XMLTV (formato por defecto)
 *   api.php?k=CLAVE&format=xml|json|m3u
 *   api.php?k=CLAVE&days=7                   → hasta 14 días
 *   api.php?k=CLAVE&channels=id1,id2         → solo esos canales (de los asignados)
 *   api.php?k=CLAVE&gzip=1                   → respuesta comprimida con gzip
 *
 * También acepta la clave por cabecera HTTP: X-Api-Key: CLAVE
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$key = $_GET['k'] ?? $_GET['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
$format = strtolower(trim((string)($_GET['format'] ?? 'xml')));
$days = (int)($_GET['days'] ?? (int)epg_setting('export_days', EPGW_DEFAULT_EXPORT_DAYS));
$days = max(1, min(EPGW_MAX_EXPORT_DAYS, $days));
$chanFilter = null;
if (isset($_GET['channels']) && trim((string)$_GET['channels']) !== '') {
    $chanFilter = array_filter(array_map('trim', explode(',', (string)$_GET['channels'])));
}

$user = epg_check_api_access($key);
if (!$user) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "EPGWeb: clave de API inválida o sin permiso.\n";
    epg_log_access(null, 'api', $format, 401, 0);
    return;
}

// canales visibles para este usuario (y filtro opcional de la URL)
$channels = epg_export_channels($user, $chanFilter);
if (!$channels) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "EPGWeb: el usuario no tiene canales asignados.\n";
    epg_log_access($user['id'], 'api', $format, 404, 0);
    return;
}

$fromTs = time() - 6 * 3600;                 // incluir el programa en emisión
$toTs   = time() + $days * 86400;

// registrar último uso
try {
    epg_db()->prepare('UPDATE users SET last_use = ? WHERE id = ?')->execute(array(time(), (int)$user['id']));
} catch (Exception $e) { /* ignorar */ }

@set_time_limit(120);
ob_start();
switch ($format) {
    case 'json': $bytes = epg_export_json($channels, $fromTs, $toTs, $user); break;
    case 'm3u':  $bytes = epg_export_m3u($channels, $user); break;
    case 'xml':
    case 'xmltv':
    default:     $format = 'xml'; $bytes = epg_export_xmltv($channels, $fromTs, $toTs, $user); break;
}

// gzip opcional (reduce mucho el XMLTV en redes lentas)
if (isset($_GET['gzip']) && function_exists('gzencode')) {
    $body = ob_get_clean();
    $gz = gzencode($body, 5);
    if ($gz !== false) {
        header('Content-Encoding: gzip');
        header('Content-Length: ' . strlen($gz));
        echo $gz;
        $bytes = strlen($gz);
    } else {
        header('Content-Length: ' . strlen($body));
        echo $body;
    }
} else {
    $body = ob_get_clean();
    header('Content-Length: ' . strlen($body));
    echo $body;
}

epg_log_access($user['id'], 'api', $format, 200, $bytes);
