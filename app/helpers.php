<?php
/**
 * EPGWeb — Funciones auxiliares: escapado, CSRF, fechas XMLTV, URLs, formato.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit('EPGWeb: acceso directo no permitido'); }

/* ------------------------- Escapado / salida ------------------------- */

/** htmlspecialchars corto. */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Igual que e() pero recorta a $max caracteres. */
function e_trunc($s, $max = 120) {
    $s = (string)$s;
    if (function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') > $max : strlen($s) > $max) {
        $s = function_exists('mb_substr') ? mb_substr($s, 0, $max - 1, 'UTF-8') : substr($s, 0, $max - 1);
        $s .= '…';
    }
    return e($s);
}

/* ------------------------------ Fechas ------------------------------- */

/** Timestamp actual (UTC). */
function epg_now() { return time(); }

/** Formatea un timestamp UTC en la zona horaria del sitio. */
function epg_fmt($ts, $fmt = 'd/m/Y H:i') {
    if (!$ts) { return '—'; }
    $tz = new DateTimeZone(epg_site_tz());
    $dt = new DateTime('@' . (int)$ts);
    $dt->setTimezone($tz);
    return $dt->format($fmt);
}

/** Hora corta (H:i) en zona del sitio. */
function epg_fmt_time($ts) { return epg_fmt($ts, 'H:i'); }

/** Fecha corta en zona del sitio. */
function epg_fmt_date($ts) { return epg_fmt($ts, 'd/m/Y'); }

/** "hace X" relativo. */
function epg_ago($ts) {
    if (!$ts) { return 'nunca'; }
    $d = time() - (int)$ts;
    if ($d < 60)     { return 'hace segundos'; }
    if ($d < 3600)   { return 'hace ' . floor($d / 60) . ' min'; }
    if ($d < 86400)  { return 'hace ' . floor($d / 3600) . ' h'; }
    return 'hace ' . floor($d / 86400) . ' d';
}

/**
 * Convierte una fecha XMLTV a timestamp UTC.
 * Acepta: "20261018120000 +0000", "20261018120000", "20261018120000Z", "20261018".
 * Si no trae zona horaria se interpreta en la zona del sitio.
 * @return int|null
 */
function epg_parse_xmltv_time($s) {
    $s = trim((string)$s);
    if ($s === '') { return null; }
    $tz = null;
    if (preg_match('/^(.+?)[ ]?([+-]\d{4}|Z)$/i', $s, $m)) {
        $s  = trim($m[1]);
        $tz = (strtoupper($m[2]) === 'Z') ? '+0000' : $m[2];
    }
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $s, $m) &&
        !preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m2)) {
        return null;
    }
    if (isset($m2)) { $m = array(null, $m2[1], $m2[2], $m2[3], 0, 0, 0); }
    $ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
    if ($ts === false) { return null; }
    if ($tz !== null) {
        $sign = ($tz[0] === '-') ? -1 : 1;
        $off  = ((int)substr($tz, 1, 2) * 3600) + ((int)substr($tz, 3, 2) * 60);
        $ts  -= $sign * $off;
    } else {
        // sin zona: interpretar como hora local del sitio
        try {
            $dt = new DateTime('@' . $ts);
            $dt->setTimezone(new DateTimeZone(epg_site_tz()));
            $ts -= $dt->getOffset();
        } catch (Exception $ex) { /* queda UTC */ }
    }
    return $ts;
}

/** Formatea un timestamp UTC al formato XMLTV "YYYYMMDDHHMMSS +ZZZZ" (zona del sitio). */
function epg_fmt_xmltv_time($ts) {
    $dt = new DateTime('@' . (int)$ts);
    $dt->setTimezone(new DateTimeZone(epg_site_tz()));
    return $dt->format('YmdHis O');
}

/** Inicio (00:00) del día indicado (Y-m-d) en zona del sitio, como timestamp UTC. */
function epg_day_start($ymd) {
    try {
        $dt = new DateTime($ymd, new DateTimeZone(epg_site_tz()));
        $dt->setTime(0, 0, 0);
        return $dt->getTimestamp();
    } catch (Exception $e) { return null; }
}

/* ------------------------------- CSRF -------------------------------- */

function csrf_token() {
    if (session_status() !== PHP_SESSION_ACTIVE) { return ''; }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Verifica el token CSRF de un POST; aborta si es inválido. */
function csrf_check() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return; }
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Sesión expirada o token inválido. Vuelve atrás y reintenta.');
    }
}

/* ------------------------------ Mensajes ------------------------------ */

function flash($type, $msg) {
    if (session_status() !== PHP_SESSION_ACTIVE) { return; }
    $_SESSION['flash'][] = array('type' => $type, 'msg' => $msg);
}

function flash_get() {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['flash'])) { return array(); }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------- URLs --------------------------------- */

/** Esquema de la petición actual (detrás de proxy). */
function epg_scheme() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { return 'https'; }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $p = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        return ($p === 'https') ? 'https' : 'http';
    }
    return 'http';
}

/** Base pública con host actual, p.ej. "https://dominio.com/epg". */
function epg_base_url() {
    $scheme = epg_scheme();
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

/** URL del endpoint de API para una clave. */
function epg_api_url($apiKey, $format = 'xml', $days = null) {
    $q = array('k' => $apiKey, 'format' => $format);
    if ($days !== null) { $q['days'] = (int)$days; }
    return epg_base_url() . '/api.php?' . http_build_query($q);
}

/** IP del cliente (soporta proxies). */
function epg_client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) { return $ip; }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/* ------------------------------ Formato ------------------------------- */

function epg_human_bytes($n) {
    $n = (float)$n;
    if ($n < 1024)          { return round($n) . ' B'; }
    if ($n < 1048576)       { return round($n / 1024, 1) . ' KB'; }
    if ($n < 1073741824)    { return round($n / 1048576, 1) . ' MB'; }
    return round($n / 1073741824, 2) . ' GB';
}

/** Enlace interno del panel. */
function u($route, array $params = array()) {
    $q = array_merge(array('r' => $route), $params);
    return 'index.php?' . http_build_query($q);
}

/** Redirige y termina. */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/** POST de esta ruta? */
function is_post() { return (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'); }
