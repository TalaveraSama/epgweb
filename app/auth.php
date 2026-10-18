<?php
/**
 * EPGWeb — Autenticación del panel, roles, claves de API y límite de intentos.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit('EPGWeb: acceso directo no permitido'); }

/** Inicia la sesión del panel (una sola vez, con parámetros seguros). */
function auth_start_session() {
    // Runtimes persistentes (FrankenPHP worker, RoadRunner, PHP-WASM, etc.)
    // pueden conservar la sesión activa entre peticiones: cerrarla y limpiar
    // para que cada petición arranque desde su propia cookie.
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    $_SESSION = array();

    $path = EPGW_DATA . '/sessions';
    if (is_dir($path) && is_writable($path)) { @ini_set('session.save_path', $path); }
    session_name('EPGWSESS');
    $secure = (epg_scheme() === 'https');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    session_start();
}

/** Usuario conectado (fila) o null; valida vigencia y expiración de la cuenta. */
function auth_user() {
    if (isset($GLOBALS['epgw_auth_user_cache'])) { return $GLOBALS['epgw_auth_user_cache']; }
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) { return $GLOBALS['epgw_auth_user_cache'] = null; }
    try {
        $st = epg_db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute(array((int)$uid));
        $u = $st->fetch();
    } catch (Exception $e) { return $GLOBALS['epgw_auth_user_cache'] = null; }
    if (!$u) { return $GLOBALS['epgw_auth_user_cache'] = null; }
    // expiración de sesión por inactividad
    $last = $_SESSION['last'] ?? 0;
    if (time() - $last > EPGW_SESSION_LIFETIME) { auth_logout(false); return $GLOBALS['epgw_auth_user_cache'] = null; }
    $_SESSION['last'] = time();
    // cuenta desactivada o vencida
    if (!$u['is_active'] || ($u['expires_at'] && $u['expires_at'] < time())) {
        auth_logout(false);
        return $GLOBALS['epgw_auth_user_cache'] = null;
    }
    return $GLOBALS['epgw_auth_user_cache'] = $u;
}

function auth_is_admin() {
    $u = auth_user();
    return $u && $u['role'] === 'admin';
}

/** Exige sesión iniciada; si no, redirige al login. */
function require_login() {
    if (!auth_user()) {
        flash('warn', 'Inicia sesión para continuar.');
        redirect(u('login'));
    }
}

/** Exige rol administrador. */
function require_admin() {
    require_login();
    if (!auth_is_admin()) {
        flash('warn', 'Solo el administrador puede entrar a esa sección.');
        redirect(u('dashboard'));
    }
}

/** Intento de login. Devuelve true si OK. */
function auth_login($username, $password) {
    $db = epg_db();
    $ip = epg_client_ip();
    $throttleKey = 'fail_' . md5(strtolower($username) . '|' . $ip);
    $fails = (int)epg_setting($throttleKey, 0);
    if ($fails >= 8) {
        // ventana de 15 min desde el último fallo
        $lastFail = (int)epg_setting($throttleKey . '_ts', 0);
        if (time() - $lastFail < 900) {
            return array(false, 'Demasiados intentos fallidos. Espera unos minutos.');
        }
        $fails = 0;
    }
    $st = $db->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute(array(trim($username)));
    $u = $st->fetch();
    $ok = $u && password_verify((string)$password, $u['password_hash']);
    if ($ok && (!$u['is_active'] || ($u['expires_at'] && $u['expires_at'] < time()))) {
        return array(false, 'La cuenta está desactivada o vencida. Contacta al administrador.');
    }
    if (!$ok) {
        epg_setting_set($throttleKey, $fails + 1);
        epg_setting_set($throttleKey . '_ts', time());
        return array(false, 'Usuario o contraseña incorrectos.');
    }
    epg_setting_set($throttleKey, 0);
    session_regenerate_id(true);
    $_SESSION['uid']  = (int)$u['id'];
    $_SESSION['last'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(20));
    $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute(array(time(), $u['id']));
    return array(true, $u);
}

/** Cierra la sesión. */
function auth_logout($full = true) {
    if ($full) {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '',
                      (bool)($p['secure'] ?? false), (bool)($p['httponly'] ?? true));
        }
    }
    session_destroy();
}

/** Genera una clave de API (32 caracteres hex). */
function epg_generate_api_key() {
    return bin2hex(random_bytes(16));
}

/** Cambia la clave de API de un usuario y devuelve la nueva. */
function epg_regen_api_key($userId) {
    $key = epg_generate_api_key();
    epg_db()->prepare('UPDATE users SET api_key = ? WHERE id = ?')->execute(array($key, (int)$userId));
    return $key;
}

/**
 * Valida una clave de API para el endpoint público.
 * @return array|null fila del usuario con acceso vigente, o null
 */
function epg_check_api_access($key) {
    $key = trim((string)$key);
    if ($key === '' || strlen($key) > 64) { return null; }
    $st = epg_db()->prepare('SELECT * FROM users WHERE api_key = ?');
    $st->execute(array($key));
    $u = $st->fetch();
    if (!$u) { return null; }
    if (!$u['is_active'])            { return null; }
    if ($u['expires_at'] && $u['expires_at'] < time()) { return null; }
    return $u;
}

/**
 * Canales visibles para un usuario.
 * @return array|null null = todos los canales habilitados
 */
function epg_user_channels($user) {
    if ($user['access_mode'] === 'all') { return null; }
    $st = epg_db()->prepare('SELECT c.* FROM user_channels uc
                             JOIN channels c ON c.id = uc.channel_id
                             WHERE uc.user_id = ? AND c.enabled = 1
                             ORDER BY c.display_name');
    $st->execute(array((int)$user['id']));
    return $st->fetchAll();
}

/** Registra un acceso API. */
function epg_log_access($userId, $endpoint, $fmt, $status, $bytes) {
    try {
        epg_db()->prepare('INSERT INTO accesses (user_id, endpoint, fmt, status, bytes, ip, user_agent, created_at)
                           VALUES (?,?,?,?,?,?,?,?)')
            ->execute(array(
                $userId ? (int)$userId : null, $endpoint, $fmt, (int)$status, (int)$bytes,
                epg_client_ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250), time(),
            ));
    } catch (Exception $e) { /* no bloquear la respuesta por el log */ }
}
