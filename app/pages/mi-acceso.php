<?php
/**
 * EPGWeb — "Mi acceso": URL personal del usuario para usar como EPG.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_login();
$user = auth_user();
$db = epg_db();

$mine = epg_user_channels($user);
$uses24 = (int)$db->query('SELECT COUNT(*) FROM accesses WHERE user_id = ' . (int)$user['id'] . ' AND created_at > ' . (time() - 86400))->fetchColumn();
$uses7d = (int)$db->query('SELECT COUNT(*) FROM accesses WHERE user_id = ' . (int)$user['id'] . ' AND created_at > ' . (time() - 7 * 86400))->fetchColumn();
$lastUse = $db->query('SELECT MAX(created_at) FROM accesses WHERE user_id = ' . (int)$user['id'])->fetchColumn();

page_header('Mi acceso EPG', 'mi-acceso');
?>
<section class="card">
  <h2 class="card-title">🔑 Tu enlace de guía EPG</h2>
  <p class="muted">Usa el enlace <strong>XMLTV</strong> en tu reproductor IPTV (campo «EPG / guía de programación»).
  El M3U sirve para importar la lista de canales con el EPG ya mapeado.</p>

  <div class="access-box">
    <?php
    $urls = array(
        'XMLTV (reproductores IPTV)' => epg_api_url($user['api_key'], 'xml'),
        'JSON (apps propias)'        => epg_api_url($user['api_key'], 'json'),
        'M3U (lista con tvg-id)'     => epg_api_url($user['api_key'], 'm3u'),
    );
    foreach ($urls as $label => $url): ?>
      <div class="url-row">
        <span class="url-label"><?= e($label) ?></span>
        <code class="url-code" id="url-<?= e(md5($url)) ?>"><?= e($url) ?></code>
        <button class="btn btn-sm copy-btn" type="button" data-copy="#url-<?= e(md5($url)) ?>">📋 Copiar</button>
      </div>
    <?php endforeach; ?>
    <p class="muted sm">Añade <code>&amp;days=N</code> para más días (1–<?= EPGW_MAX_EXPORT_DAYS ?>) o <code>&amp;channels=id1,id2</code> para filtrar.</p>
  </div>

  <div class="stats-grid mt">
    <?= stat_card('Canales asignados', $mine === null ? 'Todos' : number_format(count($mine)), '', '📺') ?>
    <?= stat_card('Usos en 24 h', number_format($uses24), number_format($uses7d) . ' en 7 días', '⚡') ?>
    <?= stat_card('Último uso', $lastUse ? epg_ago($lastUse) : 'nunca', '', '🕒') ?>
  </div>
</section>

<section class="card">
  <h2 class="card-title">📺 Mis canales</h2>
  <?php if ($mine === null): ?>
    <p class="muted">Tienes acceso a <strong>todos los canales</strong> del servicio.</p>
  <?php elseif (!$mine): ?>
    <p class="muted">El administrador aún no te asignó canales.</p>
  <?php else: ?>
    <div class="chip-cloud">
      <?php foreach ($mine as $c): ?>
        <span class="chip"><?= e($c['display_name']) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php page_footer(); ?>
