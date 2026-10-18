<?php
/**
 * EPGWeb — Fuentes EPG (origen de las guías XMLTV).
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_admin();
$db = epg_db();

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // crear fuente
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $url  = trim((string)($_POST['url'] ?? ''));
        if ($name === '' || $url === '') {
            flash('err', 'Nombre y URL son obligatorios.');
        } else {
            $db->prepare('INSERT INTO sources (name, url, enabled, created_at) VALUES (?,?,1,?)')
               ->execute(array($name, $url, time()));
            flash('ok', 'Fuente creada. Ahora actualízala para importar su guía.');
        }
        redirect(u('fuentes'));
    }

    // actualizar (descargar + importar)
    if ($action === 'refresh') {
        $id = (int)($_POST['id'] ?? 0);
        $res = epg_import_source($db, $id);
        flash($res['ok'] ? 'ok' : 'err',
              $res['ok']
                  ? sprintf('Fuente actualizada: %d canales y %d programas en %s s.', $res['channels'], $res['programmes'], $res['seconds'])
                  : 'Error actualizando la fuente: ' . $res['message']);
        redirect(u('fuentes'));
    }

    // importar archivo subido
    if ($action === 'upload') {
        $id = (int)($_POST['id'] ?? 0);
        $up = epg_store_upload($_FILES['xml_file'] ?? array());
        if (!$up[0]) {
            flash('err', $up[1]);
        } else {
            $res = epg_import_source($db, $id, $up[1]);
            flash($res['ok'] ? 'ok' : 'err',
                  $res['ok']
                      ? sprintf('Archivo importado: %d canales y %d programas en %s s.', $res['channels'], $res['programmes'], $res['seconds'])
                      : 'Error importando el archivo: ' . $res['message']);
            @unlink($up[1]);
        }
        redirect(u('fuentes'));
    }

    // activar/desactivar
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE sources SET enabled = 1 - enabled WHERE id = ?')->execute(array($id));
        flash('ok', 'Estado de la fuente cambiado.');
        redirect(u('fuentes'));
    }

    // borrar
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        epg_delete_source($db, $id);
        flash('ok', 'Fuente eliminada junto con sus canales y programas.');
        redirect(u('fuentes'));
    }
}

$sources = $db->query('SELECT * FROM sources ORDER BY id')->fetchAll();
$refreshes = $db->query('SELECT r.*, s.name AS src_name FROM refreshes r
                         LEFT JOIN sources s ON s.id = r.source_id
                         ORDER BY r.id DESC LIMIT 20')->fetchAll();

page_header('Fuentes EPG', 'fuentes');
?>
<section class="card">
  <h2 class="card-title">🛰️ Fuentes de guía (XMLTV)</h2>
  <p class="muted">Una fuente es un servidor que entrega un XMLTV, como tu Astra (<code><?= e(EPGW_DEFAULT_SOURCE_URL) ?></code>).
  «Actualizar» descarga la guía y la guarda en la base de datos; los clientes reciben sus guías desde aquí.</p>

  <table class="table">
    <thead>
      <tr><th>#</th><th>Nombre / URL</th><th>Estado</th><th>Canales</th><th>Programas</th><th>Último refresco</th><th class="nowrap">Acciones</th></tr>
    </thead>
    <tbody>
    <?php foreach ($sources as $s): ?>
      <tr>
        <td class="muted"><?= (int)$s['id'] ?></td>
        <td>
          <strong><?= e($s['name']) ?></strong><?= $s['enabled'] ? '' : ' <span class="badge badge-err">desactivada</span>' ?>
          <div class="muted sm"><?= e_trunc($s['url'], 70) ?></div>
          <?php if ($s['last_message']): ?><div class="muted sm">↳ <?= e_trunc($s['last_message'], 90) ?></div><?php endif; ?>
        </td>
        <td>
          <?php if ($s['last_status'] === 'ok'): ?><span class="badge badge-ok">OK</span>
          <?php elseif ($s['last_status'] === 'error'): ?><span class="badge badge-err" title="<?= e($s['last_message']) ?>">Error</span>
          <?php else: ?><span class="badge">Sin datos</span><?php endif; ?>
        </td>
        <td class="muted"><?= (int)$s['last_channels'] ?></td>
        <td class="muted"><?= number_format((int)$s['last_programmes']) ?></td>
        <td class="nowrap muted"><?= epg_fmt($s['last_refresh'], 'd/m H:i') ?><div class="sm"><?= epg_ago($s['last_refresh']) ?></div></td>
        <td class="nowrap">
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="refresh">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm btn-primary" type="submit">↻ Actualizar</button>
          </form>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm" type="submit"><?= $s['enabled'] ? '⏸ Desactivar' : '▶ Activar' ?></button>
          </form>
          <form method="post" class="inline" data-confirm="¿Eliminar la fuente «<?= e($s['name']) ?>» y TODOS sus canales/programas?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit">🗑</button>
          </form>
          <details class="upload-details">
            <summary class="btn btn-sm">📂 Subir XML</summary>
            <form method="post" enctype="multipart/form-data" class="upload-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="upload">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <input type="file" name="xml_file" accept=".xml,.xmltv,.gz,text/xml,application/xml" required>
              <button class="btn btn-sm btn-primary" type="submit">Importar archivo</button>
            </form>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sources): ?>
      <tr><td colspan="7" class="muted">Sin fuentes. Agrega una abajo.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <h3 class="mt">➕ Agregar fuente</h3>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label class="field grow"><span>Nombre</span>
      <input type="text" name="name" placeholder="Ej: Astra principal" required maxlength="60"></label>
    <label class="field grow"><span>URL del XMLTV</span>
      <input type="text" name="url" placeholder="http://servidor:puerto/guia.xml" required></label>
    <button class="btn btn-primary" type="submit">Guardar fuente</button>
  </form>
</section>

<section class="card">
  <h2 class="card-title">📜 Historial de refrescos</h2>
  <table class="table">
    <thead><tr><th>Cuándo</th><th>Fuente</th><th>Estado</th><th>Canales</th><th>Programas</th><th>Detalle</th></tr></thead>
    <tbody>
    <?php foreach ($refreshes as $r): ?>
      <tr>
        <td class="nowrap muted"><?= epg_fmt($r['finished_at'], 'd/m H:i') ?></td>
        <td><?= e_trunc($r['src_name'] ?? ('#' . $r['source_id']), 26) ?></td>
        <td><?php if ($r['status'] === 'ok'): ?><span class="badge badge-ok">OK</span><?php else: ?><span class="badge badge-err">Error</span><?php endif; ?></td>
        <td class="muted"><?= (int)$r['channels'] ?></td>
        <td class="muted"><?= number_format((int)$r['programmes']) ?></td>
        <td class="muted sm"><?= e_trunc($r['message'], 80) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$refreshes): ?><tr><td colspan="6" class="muted">Sin historial todavía.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="muted sm">💡 Para actualizaciones automáticas configura un cron: <code>php <?= e(EPGW_ROOT) ?>/cron/refresh.php all</code></p>
</section>
<?php page_footer(); ?>
