<?php
/**
 * EPGWeb — Plantilla común del panel (cabecera, menú lateral, pie).
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

/** Menú del panel según rol. */
function epg_menu($user) {
    $items = array(
        array('r' => 'dashboard',  'icon' => '📊', 'label' => 'Panel'),
        array('r' => 'guia',       'icon' => '📅', 'label' => 'Guía EPG'),
    );
    if ($user && $user['role'] === 'admin') {
        $items[] = array('r' => 'canales',   'icon' => '📺', 'label' => 'Canales');
        $items[] = array('r' => 'fuentes',   'icon' => '🛰️', 'label' => 'Fuentes');
        $items[] = array('r' => 'usuarios',  'icon' => '👥', 'label' => 'Usuarios y accesos');
        $items[] = array('r' => 'accesos',   'icon' => '📜', 'label' => 'Registros');
        $items[] = array('r' => 'ajustes',   'icon' => '⚙️', 'label' => 'Ajustes');
    } else {
        $items[] = array('r' => 'mi-acceso', 'icon' => '🔑', 'label' => 'Mi acceso EPG');
    }
    return $items;
}

/** Abre el HTML del panel. */
function page_header($title, $active) {
    $user = auth_user();
    $siteName = epg_setting('site_name', 'EPGWeb');
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($siteName) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= EPGW_VERSION ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="brand-logo">📡</span>
      <div>
        <div class="brand-name"><?= e($siteName) ?></div>
        <div class="brand-sub">Panel EPG v<?= EPGW_VERSION ?></div>
      </div>
    </div>
    <nav class="nav">
      <?php foreach (epg_menu($user) as $it): ?>
        <a class="nav-item<?= ($active === $it['r']) ? ' active' : '' ?>" href="<?= e(u($it['r'])) ?>">
          <span class="nav-ico"><?= $it['icon'] ?></span><?= e($it['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <?php if ($user): ?>
        <div class="who">
          <span class="who-avatar"><?= e(strtoupper(substr($user['username'], 0, 1))) ?></span>
          <div>
            <div class="who-name"><?= e($user['username']) ?></div>
            <div class="who-role"><?= $user['role'] === 'admin' ? 'Administrador' : 'Usuario' ?></div>
          </div>
        </div>
        <a class="nav-item" href="<?= e(u('perfil')) ?>">👤 Mi perfil</a>
        <a class="nav-item" href="<?= e(u('logout')) ?>">🚪 Cerrar sesión</a>
      <?php endif; ?>
    </div>
  </aside>
  <div class="backdrop" id="backdrop"></div>
  <main class="main">
    <header class="topbar">
      <button class="burger" id="burger" aria-label="Menú">☰</button>
      <h1 class="page-title"><?= e($title) ?></h1>
      <span class="tz-badge" title="Zona horaria del servicio"><?= e(epg_site_tz()) ?> · <?= epg_fmt_date(time()) ?></span>
    </header>
    <div class="content">
      <?php foreach (flash_get() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
<?php
}

/** Cierra el HTML del panel. */
function page_footer() {
?>
    </div><!-- /content -->
    <footer class="footer">
      <span>EPGWeb <?= EPGW_VERSION ?> — servicio de guía de programación (XMLTV)</span>
    </footer>
  </main>
</div>
<script src="assets/app.js?v=<?= EPGW_VERSION ?>"></script>
</body>
</html>
<?php
}

/** Renderiza una "tarjeta" de estadística. */
function stat_card($label, $value, $sub = '', $icon = '📈') {
    return '<div class="stat-card"><div class="stat-ico">' . $icon . '</div><div>' .
           '<div class="stat-value">' . e($value) . '</div>' .
           '<div class="stat-label">' . e($label) . '</div>' .
           ($sub !== '' ? '<div class="stat-sub">' . e($sub) . '</div>' : '') .
           '</div></div>';
}
