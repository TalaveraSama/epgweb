<?php
/**
 * EPGWeb — Panel principal (resumen del servicio).
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_login();
$user = auth_user();
$db = epg_db();
$isAdmin = ($user['role'] === 'admin');

$totChannels  = (int)$db->query('SELECT COUNT(*) FROM channels WHERE enabled = 1')->fetchColumn();
$totPrograms  = (int)$db->query('SELECT COUNT(*) FROM programmes')->fetchColumn();
$next24       = (int)$db->query('SELECT COUNT(*) FROM programmes WHERE starts_at BETWEEN ' . time() . ' AND ' . (time() + 86400))->fetchColumn();
$sources      = $db->query('SELECT * FROM sources ORDER BY id')->fetchAll();
$srcOk        = count(array_filter($sources, function ($s) { return $s['last_status'] === 'ok'; }));

if ($isAdmin) {
    $totUsers   = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $acc24      = (int)$db->query('SELECT COUNT(*) FROM accesses WHERE created_at > ' . (time() - 86400))->fetchColumn();
    $lastRefreshes = $db->query('SELECT r.*, s.name AS src_name FROM refreshes r LEFT JOIN sources s ON s.id = r.source_id ORDER BY r.id DESC LIMIT 6')->fetchAll();
    $lastAccesses  = $db->query('SELECT a.*, u.username FROM accesses a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8')->fetchAll();
} else {
    $mine = epg_user_channels($user);
    $myCh = $mine === null ? $totChannels : count($mine);
    $acc24 = (int)$db->query('SELECT COUNT(*) FROM accesses WHERE user_id = ' . (int)$user['id'] . ' AND created_at > ' . (time() - 86400))->fetchColumn();
}

// "ahora en emisión" (hasta 10 canales)
$nowPlaying = array();
if ($isAdmin) {
    $chList = $db->query('SELECT * FROM channels WHERE enabled = 1 AND programme_count > 0 ORDER BY display_name COLLATE NOCASE LIMIT 10')->fetchAll();
} else {
    $chList = array_slice($mine, 0, 10);
}
foreach ($chList as $c) {
    $np = epg_now_playing($c['source_id'], $c['xmltv_id']);
    if ($np) { $nowPlaying[] = array('ch' => $c, 'pr' => $np); }
}

page_header('Panel', 'dashboard');
?>
<div class="stats-grid">
  <?= stat_card('Canales activos', number_format($totChannels), '', '📺') ?>
  <?= stat_card('Programas en guía', number_format($totPrograms), number_format($next24) . ' en 24 h', '🗓️') ?>
  <?= stat_card('Fuentes EPG', count($sources), $srcOk . ' correctas', '🛰️') ?>
  <?php if ($isAdmin): ?>
    <?= stat_card('Usuarios activos', number_format($totUsers), number_format($acc24) . ' usos API en 24 h', '👥') ?>
  <?php else: ?>
    <?= stat_card('Mis canales', number_format($myCh), number_format($acc24) . ' usos en 24 h', '🔑') ?>
  <?php endif; ?>
</div>

<div class="grid-2">
  <section class="card">
    <h2 class="card-title">▶️ Ahora en emisión</h2>
    <?php if (!$nowPlaying): ?>
      <p class="muted">Sin datos todavía. <?= $isAdmin ? 'Importa o actualiza una fuente EPG primero.' : 'El administrador aún no importó la guía.' ?></p>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Canal</th><th>Programa</th><th>Hasta</th></tr></thead>
      <tbody>
      <?php foreach ($nowPlaying as $row): ?>
        <tr>
          <td class="nowrap"><strong><?= e_trunc($row['ch']['display_name'], 26) ?></strong></td>
          <td>
            <?= e_trunc($row['pr']['title'], 46) ?>
            <?php if ($row['pr']['category']): ?><span class="badge"><?= e_trunc($row['pr']['category'], 18) ?></span><?php endif; ?>
            <div class="bar"><div class="bar-fill" style="width:<?= min(100, max(0, round((time() - $row['pr']['starts_at']) / max(60, $row['pr']['ends_at'] - $row['pr']['starts_at']) * 100))) ?>%"></div></div>
          </td>
          <td class="nowrap muted"><?= epg_fmt_time($row['pr']['ends_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <?php if ($isAdmin): ?>
  <section class="card">
    <h2 class="card-title">🛰️ Fuentes</h2>
    <?php if (!$sources): ?>
      <p class="muted">Aún no hay fuentes. <a href="<?= e(u('fuentes')) ?>">Agrega la primera</a>.</p>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Fuente</th><th>Estado</th><th>Último refresco</th></tr></thead>
      <tbody>
      <?php foreach ($sources as $s): ?>
        <tr>
          <td><strong><?= e_trunc($s['name'], 30) ?></strong><div class="muted sm"><?= e_trunc($s['url'], 44) ?></div></td>
          <td>
            <?php if ($s['last_status'] === 'ok'): ?><span class="badge badge-ok">OK</span>
            <?php elseif ($s['last_status'] === 'error'): ?><span class="badge badge-err">Error</span>
            <?php else: ?><span class="badge">Sin datos</span><?php endif; ?>
          </td>
          <td class="nowrap muted"><?= epg_ago($s['last_refresh']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<div class="grid-2">
  <section class="card">
    <h2 class="card-title">📜 Últimos refrescos</h2>
    <?php if (!$lastRefreshes): ?><p class="muted">Sin refrescos registrados.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Fuente</th><th>Estado</th><th>Canales/Programas</th><th>Cuándo</th></tr></thead>
      <tbody>
      <?php foreach ($lastRefreshes as $r): ?>
        <tr>
          <td><?= e_trunc($r['src_name'] ?? '—', 24) ?></td>
          <td><?php if ($r['status'] === 'ok'): ?><span class="badge badge-ok">OK</span><?php else: ?><span class="badge badge-err">Error</span><?php endif; ?></td>
          <td class="muted"><?= (int)$r['channels'] ?> / <?= number_format((int)$r['programmes']) ?></td>
          <td class="nowrap muted"><?= epg_fmt($r['finished_at'], 'd/m H:i') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2 class="card-title">🔑 Últimos accesos API</h2>
    <?php if (!$lastAccesses): ?><p class="muted">Todavía nadie ha usado la API.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Usuario</th><th>Formato</th><th>Tamaño</th><th>Cuándo</th></tr></thead>
      <tbody>
      <?php foreach ($lastAccesses as $a): ?>
        <tr>
          <td><?= e($a['username'] ?? 'anónimo') ?></td>
          <td><span class="badge"><?= e(strtoupper($a['fmt'])) ?></span></td>
          <td class="muted"><?= epg_human_bytes((int)$a['bytes']) ?></td>
          <td class="nowrap muted"><?= epg_fmt($a['created_at'], 'd/m H:i') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>
<?php page_footer(); ?>
