<?php
/**
 * EPGWeb — Navegador de la guía (programación por canal/día y "ahora").
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_login();
$user = auth_user();
$db = epg_db();

// canales visibles para este usuario
if ($user['role'] === 'admin') {
    $channels = $db->query('SELECT c.*, s.name AS src_name FROM channels c
                            LEFT JOIN sources s ON s.id = c.source_id
                            WHERE c.enabled = 1 ORDER BY c.display_name COLLATE NOCASE')->fetchAll();
} else {
    $st = $db->prepare('SELECT c.*, s.name AS src_name FROM user_channels uc
                        JOIN channels c ON c.id = uc.channel_id
                        LEFT JOIN sources s ON s.id = c.source_id
                        WHERE uc.user_id = ? AND c.enabled = 1
                        ORDER BY c.display_name COLLATE NOCASE');
    $st->execute(array((int)$user['id']));
    $channels = $st->fetchAll();
}

$mode = ($_GET['modo'] ?? 'dia');
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
$chId = (int)($_GET['ch'] ?? 0);
$sel = null;
foreach ($channels as $c) { if ((int)$c['id'] === $chId) { $sel = $c; break; } }
if (!$sel && $channels) { $sel = $channels[0]; $chId = (int)$sel['id']; }

page_header('Guía EPG', 'guia');
?>
<div class="tabs">
  <a class="tab<?= $mode !== 'ahora' ? ' active' : '' ?>" href="<?= e(u('guia', array('modo' => 'dia', 'ch' => $chId, 'date' => $date))) ?>">📅 Por día</a>
  <a class="tab<?= $mode === 'ahora' ? ' active' : '' ?>" href="<?= e(u('guia', array('modo' => 'ahora'))) ?>">▶️ Ahora en emisión</a>
</div>

<?php if (!$channels): ?>
  <section class="card"><p class="muted">No hay canales visibles todavía.</p></section>
<?php elseif ($mode === 'ahora'): ?>
  <?php
  $now = time();
  ?>
  <section class="card">
    <h2 class="card-title">▶️ Emitiéndose ahora (<?= epg_fmt($now, 'H:i') ?>)</h2>
    <table class="table">
      <thead><tr><th>Canal</th><th>Programa</th><th>Horario</th><th>Progreso</th></tr></thead>
      <tbody>
      <?php foreach ($channels as $c):
          $np = epg_now_playing($c['source_id'], $c['xmltv_id']);
          if (!$np) { continue; }
          $pct = min(100, max(0, round(($now - $np['starts_at']) / max(60, $np['ends_at'] - $np['starts_at']) * 100)));
      ?>
        <tr>
          <td class="nowrap"><strong><?= e_trunc($c['display_name'], 30) ?></strong></td>
          <td>
            <?= e($np['title']) ?>
            <?php if ($np['category']): ?><span class="badge"><?= e_trunc($np['category'], 20) ?></span><?php endif; ?>
            <?php if ($np['sub_title']): ?><div class="muted sm"><?= e_trunc($np['sub_title'], 70) ?></div><?php endif; ?>
          </td>
          <td class="nowrap muted"><?= epg_fmt_time($np['starts_at']) ?>–<?= epg_fmt_time($np['ends_at']) ?></td>
          <td class="w-140"><div class="bar"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php else: ?>
  <?php
  $dayStart = epg_day_start($date);
  $dayEnd = $dayStart + 86400;
  $progs = $sel ? epg_programmes_between($sel['source_id'], $sel['xmltv_id'], $dayStart, $dayEnd) : array();
  $prevDay = date('Y-m-d', $dayStart - 86400);
  $nextDay = date('Y-m-d', $dayStart + 86400);
  ?>
  <section class="card">
    <h2 class="card-title">📅 Programación de <?= $sel ? e($sel['display_name']) : '—' ?></h2>

    <form method="get" class="form-inline mb">
      <input type="hidden" name="r" value="guia">
      <input type="hidden" name="modo" value="dia">
      <label class="field grow"><span>Canal</span>
        <select name="ch">
          <?php foreach ($channels as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $chId ? 'selected' : '') ?>><?= e($c['display_name']) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="field"><span>Fecha</span>
        <input type="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d', time() + 86400 * 21) ?>"></label>
      <button class="btn btn-primary" type="submit">Ver</button>
    </form>

    <div class="pagination mb">
      <a class="btn btn-sm" href="<?= e(u('guia', array('modo' => 'dia', 'ch' => $chId, 'date' => $prevDay))) ?>">← <?= epg_fmt_date($dayStart - 86400) ?></a>
      <strong class="muted"><?= epg_fmt_date($dayStart) ?> (<?= $date === date('Y-m-d') ? 'hoy' : e(date('l', strtotime($date))) ?>)</strong>
      <a class="btn btn-sm" href="<?= e(u('guia', array('modo' => 'dia', 'ch' => $chId, 'date' => $nextDay))) ?>"><?= epg_fmt_date($dayStart + 86400) ?> →</a>
    </div>

    <table class="table">
      <thead><tr><th>Hora</th><th>Programa</th><th>Categoría</th><th>Detalle</th></tr></thead>
      <tbody>
      <?php $now = time(); foreach ($progs as $p): $isNow = ($p['starts_at'] <= $now && $p['ends_at'] > $now); ?>
        <tr class="<?= $isNow ? 'row-now' : '' ?>">
          <td class="nowrap muted"><?= epg_fmt_time($p['starts_at']) ?>–<?= epg_fmt_time($p['ends_at']) ?></td>
          <td>
            <strong><?= e($p['title']) ?></strong>
            <?php if ($isNow): ?><span class="badge badge-ok">ahora</span><?php endif; ?>
            <?php if ($p['sub_title']): ?><div class="sm muted"><?= e_trunc($p['sub_title'], 80) ?></div><?php endif; ?>
          </td>
          <td><?php if ($p['category']): ?><span class="badge"><?= e($p['category']) ?></span><?php endif; ?></td>
          <td class="muted sm"><?= e_trunc($p['descr'], 110) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$progs): ?>
        <tr><td colspan="4" class="muted">Sin programación para este día (la fuente puede cubrir solo algunos días).</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
<?php page_footer(); ?>
