<?php
/**
 * EPGWeb — Registros: accesos a la API.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_admin();
$db = epg_db();

$accesses = $db->query('SELECT a.*, u.username FROM accesses a
                        LEFT JOIN users u ON u.id = a.user_id
                        ORDER BY a.id DESC LIMIT 300')->fetchAll();

// resumen por usuario (7 días)
$perUser = $db->query('SELECT u.username, COUNT(*) AS n, SUM(a.bytes) AS bytes, MAX(a.created_at) AS last
                       FROM accesses a JOIN users u ON u.id = a.user_id
                       WHERE a.created_at > ' . (time() - 7 * 86400) . '
                       GROUP BY u.id ORDER BY n DESC LIMIT 20')->fetchAll();

page_header('Registros de acceso', 'accesos');
?>
<section class="card">
  <h2 class="card-title">📊 Uso de la API (últimos 7 días)</h2>
  <?php if (!$perUser): ?><p class="muted">Sin uso registrado.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Usuario</th><th>Peticiones</th><th>Tráfico</th><th>Última</th></tr></thead>
    <tbody>
    <?php foreach ($perUser as $r): ?>
      <tr>
        <td><strong><?= e($r['username']) ?></strong></td>
        <td class="muted"><?= number_format((int)$r['n']) ?></td>
        <td class="muted"><?= epg_human_bytes((int)$r['bytes']) ?></td>
        <td class="muted nowrap"><?= epg_ago($r['last']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="card">
  <h2 class="card-title">📜 Últimos accesos (<?= count($accesses) ?>)</h2>
  <table class="table">
    <thead><tr><th>Cuándo</th><th>Usuario</th><th>Formato</th><th>Estado</th><th>Tamaño</th><th>IP</th><th>Cliente</th></tr></thead>
    <tbody>
    <?php foreach ($accesses as $a): ?>
      <tr>
        <td class="nowrap muted"><?= epg_fmt($a['created_at'], 'd/m H:i:s') ?></td>
        <td><?= e($a['username'] ?? '—') ?></td>
        <td><span class="badge"><?= e(strtoupper($a['fmt'])) ?></span></td>
        <td><?= (int)$a['status'] === 200 ? '<span class="badge badge-ok">200</span>' : '<span class="badge badge-err">' . (int)$a['status'] . '</span>' ?></td>
        <td class="muted"><?= epg_human_bytes((int)$a['bytes']) ?></td>
        <td class="muted sm"><?= e($a['ip']) ?></td>
        <td class="muted sm"><?= e_trunc($a['user_agent'], 42) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$accesses): ?><tr><td colspan="7" class="muted">Sin accesos todavía.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php page_footer(); ?>
