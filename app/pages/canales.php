<?php
/**
 * EPGWeb — Catálogo de canales.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_admin();
$db = epg_db();

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE channels SET enabled = 1 - enabled WHERE id = ?')->execute(array($id));
        flash('ok', 'Canal actualizado (los canales desactivados no aparecen en las guías entregadas).');
    }
    redirect(u('canales', array_filter(array(
        'q' => $_GET['q'] ?? '', 'src' => $_GET['src'] ?? '', 'p' => $_GET['p'] ?? '',
    ))));
}

$q   = trim((string)($_GET['q'] ?? ''));
$src = (int)($_GET['src'] ?? 0);
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 60;

$where = array(); $args = array();
if ($q !== '') {
    $where[] = '(display_name LIKE ? OR xmltv_id LIKE ?)';
    $args[] = "%$q%"; $args[] = "%$q%";
}
if ($src > 0) { $where[] = 'source_id = ?'; $args[] = $src; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = $db->prepare("SELECT COUNT(*) FROM channels $whereSql");
$total->execute($args);
$total = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);

$st = $db->prepare("SELECT c.*, s.name AS src_name FROM channels c
                    LEFT JOIN sources s ON s.id = c.source_id
                    $whereSql ORDER BY c.display_name COLLATE NOCASE
                    LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$channels = $st->fetchAll();

$sources = $db->query('SELECT id, name FROM sources ORDER BY name')->fetchAll();

page_header('Canales', 'canales');
?>
<section class="card">
  <h2 class="card-title">📺 Catálogo de canales (<?= number_format($total) ?>)</h2>

  <form method="get" class="form-inline mb">
    <input type="hidden" name="r" value="canales">
    <label class="field grow"><span>Buscar</span>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Nombre o id XMLTV…"></label>
    <label class="field"><span>Fuente</span>
      <select name="src">
        <option value="0">Todas</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ($src === (int)$s['id'] ? 'selected' : '') ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select></label>
    <button class="btn btn-primary" type="submit">Filtrar</button>
  </form>

  <table class="table">
    <thead><tr><th></th><th>Canal</th><th>Id XMLTV (tvg-id)</th><th>Fuente</th><th>Programas</th><th>Cobertura</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($channels as $c): ?>
      <tr class="<?= $c['enabled'] ? '' : 'row-off' ?>">
        <td><?php if ($c['icon']): ?><img class="ch-logo" src="<?= e($c['icon']) ?>" alt="" loading="lazy" onerror="this.style.display='none'"><?php endif; ?></td>
        <td><strong><?= e($c['display_name']) ?></strong></td>
        <td class="muted sm"><?= e_trunc($c['xmltv_id'], 40) ?></td>
        <td class="muted sm"><?= e_trunc($c['src_name'] ?? '—', 20) ?></td>
        <td class="muted"><?= number_format((int)$c['programme_count']) ?></td>
        <td class="muted sm nowrap"><?= $c['first_start'] ? epg_fmt_date($c['first_start']) . ' → ' . epg_fmt_date($c['last_stop']) : '—' ?></td>
        <td><?= $c['enabled'] ? '<span class="badge badge-ok">visible</span>' : '<span class="badge">oculto</span>' ?></td>
        <td>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-sm" type="submit"><?= $c['enabled'] ? 'Ocultar' : 'Mostrar' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$channels): ?><tr><td colspan="8" class="muted">Sin resultados<?= $q ? ' para «' . e($q) . '»' : '' ?>. Importa una fuente primero.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php
    $base = array('r' => 'canales', 'q' => $q, 'src' => $src);
    if ($page > 1) { echo '<a class="btn btn-sm" href="' . e(u('canales', $base + array('p' => $page - 1))) . '">← Anterior</a>'; }
    echo '<span class="muted">Página ' . $page . ' de ' . $pages . '</span>';
    if ($page < $pages) { echo '<a class="btn btn-sm" href="' . e(u('canales', $base + array('p' => $page + 1))) . '">Siguiente →</a>'; }
    ?>
  </div>
  <?php endif; ?>
</section>
<?php page_footer(); ?>
