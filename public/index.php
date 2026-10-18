<?php
/**
 * EPGWeb — Punto de entrada del panel.
 *
 * Enrutado simple por query (?r=pagina) para funcionar en cualquier
 * hosting compartido sin mod_rewrite.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/../app/layout.php';

auth_start_session();

// rutas disponibles (archivo => requiere)
$routes = array(
    'install'    => null,      // solo si no instalado
    'login'      => null,
    'logout'     => null,
    'dashboard'  => 'login',
    'guia'       => 'login',
    'canales'    => 'admin',
    'fuentes'    => 'admin',
    'usuarios'   => 'admin',
    'accesos'    => 'admin',
    'ajustes'    => 'admin',
    'perfil'     => 'login',
    'mi-acceso'  => 'login',
);

$installed = epg_installed();
$r = (string)($_GET['r'] ?? '');

if (!$installed) { $r = 'install'; }
if ($r === '' || !array_key_exists($r, $routes)) { $r = $installed ? 'dashboard' : 'install'; }
if ($installed && $r === 'install') { $r = 'dashboard'; }

// logout: cerrar y volver al login
if ($r === 'logout') {
    auth_logout();
    header('Location: index.php?r=login');
    exit;
}

// exigencias de la ruta
$need = $routes[$r];
if ($need === 'login')  { require_login(); }
if ($need === 'admin')  { require_admin(); }

require __DIR__ . '/../app/pages/' . $r . '.php';
