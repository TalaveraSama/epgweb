<?php
/**
 * EPGWeb — Usuarios y accesos al EPG (creación, claves API, canales asignados).
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_admin();
$db = epg_db();

/** Lista de usuarios con número de canales asignados y accesos. */
function epg_users_list(PDO $db) {
    return $db->query('
        SELECT u.*,
               (SELECT COUNT(*) FROM user_channels uc WHERE uc.user_id = u.id) AS assigned,
               (SELECT COUNT(*) FROM accesses a WHERE a.user_id = u.id AND a.created_at > ' . (time() - 86400) . ') AS uses24
        FROM users u ORDER BY u.id')->fetchAll();
}

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id       = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role     = in_array($_POST['role'] ?? '', array('admin', 'user'), true) ? $_POST['role'] : 'user';
        $mode     = in_array($_POST['access_mode'] ?? '', array('all', 'selected'), true) ? $_POST['access_mode'] : 'all';
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $notes    = trim((string)($_POST['notes'] ?? ''));
        $expires  = trim((string)($_POST['expires_at'] ?? ''));
        $expiresTs = null;
        if ($expires !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', $expires);
            if ($d) { $d->modify('+1 day -1 second'); $expiresTs = $d->getTimestamp(); }
        }
        $chIds = array();
        if ($mode === 'selected' && isset($_POST['channels']) && is_array($_POST['channels'])) {
            foreach ($_POST['channels'] as $cid) { $chIds[] = (int)$cid; }
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
            flash('err', 'Usuario inválido (3–30 caracteres alfanuméricos).');
            redirect(u('usuarios'));
        }
        if ($action === 'create' && strlen($password) < 8) {
            flash('err', 'La contraseña inicial debe tener al menos 8 caracteres.');
            redirect(u('usuarios'));
        }

        if ($action === 'create') {
            $st = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $st->execute(array($username));
            if ($st->fetchColumn() > 0) {
                flash('err', 'Ese nombre de usuario ya existe.');
                redirect(u('usuarios'));
            }
            $db->prepare('INSERT INTO users (username, password_hash, role, api_key, access_mode, is_active, expires_at, notes, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute(array($username, password_hash($password, PASSWORD_DEFAULT), $role,
                               epg_generate_api_key(), $mode, $active, $expiresTs, $notes, time()));
            $id = (int)$db->lastInsertId();
            flash('ok', "Usuario «{$username}» creado. Copia su URL de acceso abajo.");
        } else {
            if ($password !== '') {
                $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                   ->execute(array(password_hash($password, PASSWORD_DEFAULT), $id));
            }
            $db->prepare('UPDATE users SET username = ?, role = ?, access_mode = ?, is_active = ?, expires_at = ?, notes = ? WHERE id = ?')
               ->execute(array($username, $role, $mode, $active, $expiresTs, $notes, $id));
            flash('ok', "Usuario «{$username}» actualizado.");
        }

        // asignación de canales
        $db->prepare('DELETE FROM user_channels WHERE user_id = ?')->execute(array($id));
        if ($mode === 'selected' && $chIds) {
            $ins = $db->prepare('INSERT OR IGNORE INTO user_channels (user_id, channel_id) VALUES (?,?)');
            foreach ($chIds as $cid) { $ins->execute(array($id, $cid)); }
        }
        redirect(u('usuarios', array('edit' => $id)));
    }

    if ($action === 'regen') {
        $id = (int)($_POST['id'] ?? 0);
        $key = epg_regen_api_key($id);
        flash('ok', 'Nueva clave generada: ' . $key . ' (las URL anteriores dejan de funcionar).');
        redirect(u('usuarios', array('edit' => $id)));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)auth_user()['id']) {
            flash('err', 'No puedes eliminar tu propia cuenta.');
        } else {
            $db->prepare('DELETE FROM user_channels WHERE user_id = ?')->execute(array($id));
            $db->prepare('DELETE FROM users WHERE id = ?')->execute(array($id));
            flash('ok', 'Usuario eliminado.');
        }
        redirect(u('usuarios'));
    }
}

$users = epg_users_list($db);
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
foreach ($users as $u) { if ((int)$u['id'] === $editId) { $edit = $u; break; } }

$allChannels = $db->query('SELECT c.id, c.display_name, c.xmltv_id, c.enabled, s.name AS src_name
                           FROM channels c LEFT JOIN sources s ON s.id = c.source_id
                           ORDER BY s.name, c.display_name COLLATE NOCASE')->fetchAll();
$assigned = array();
if ($edit) {
    $st = $db->prepare('SELECT channel_id FROM user_channels WHERE user_id = ?');
    $st->execute(array((int)$edit['id']));
    foreach ($st->fetchAll() as $r) { $assigned[(int)$r['channel_id']] = true; }
}

page_header('Usuarios y accesos', 'usuarios');
?>
<section class="card">
  <h2 class="card-title">👥 Usuarios con acceso al panel y al EPG</h2>
  <p class="muted">Cada usuario recibe una <strong>clave de API</strong> y una URL personalizada para usar como guía EPG
  (XMLTV/JSON/M3U) en reproductores IPTV. Los usuarios de rol «usuario» solo ven la guía y su propio acceso.</p>

  <table class="table">
    <thead><tr><th>Usuario</th><th>Rol</th><th>Acceso</th><th>Estado</th><th>Usos API 24h</th><th>Último uso</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <strong><?= e($u['username']) ?></strong>
          <?php if ($u['notes']): ?><div class="muted sm"><?= e_trunc($u['notes'], 40) ?></div><?php endif; ?>
        </td>
        <td><?= $u['role'] === 'admin' ? '<span class="badge badge-ok">admin</span>' : '<span class="badge">usuario</span>' ?></td>
        <td class="muted"><?= $u['access_mode'] === 'all' ? 'todos los canales' : ((int)$u['assigned'] . ' canales') ?></td>
        <td>
          <?php if (!$u['is_active']): ?><span class="badge badge-err">desactivado</span>
          <?php elseif ($u['expires_at'] && $u['expires_at'] < time()): ?><span class="badge badge-err">vencido</span>
          <?php else: ?><span class="badge badge-ok">activo<?= $u['expires_at'] ? ' hasta ' . epg_fmt_date($u['expires_at']) : '' ?></span><?php endif; ?>
        </td>
        <td class="muted"><?= (int)$u['uses24'] ?></td>
        <td class="nowrap muted"><?= epg_ago($u['last_use']) ?></td>
        <td class="nowrap">
          <a class="btn btn-sm" href="<?= e(u('usuarios', array('edit' => $u['id']))) ?>">✏️ Editar</a>
          <form method="post" class="inline" data-confirm="¿Regenerar la clave de «<?= e($u['username']) ?>»? Sus URL actuales dejarán de funcionar.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="regen"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn btn-sm" type="submit">🔑 Regenerar</button>
          </form>
          <form method="post" class="inline" data-confirm="¿Eliminar a «<?= e($u['username']) ?>»?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit">🗑</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2 class="card-title"><?= $edit ? '✏️ Editar usuario: ' . e($edit['username']) : '➕ Crear usuario' ?></h2>

  <?php if ($edit): ?>
    <div class="access-box">
      <h3>🔑 URL de acceso EPG de <?= e($edit['username']) ?></h3>
      <?php
      $urls = array(
          'XMLTV (reproductores IPTV)' => epg_api_url($edit['api_key'], 'xml'),
          'JSON (apps propias)'        => epg_api_url($edit['api_key'], 'json'),
          'M3U (lista con tvg-id)'     => epg_api_url($edit['api_key'], 'm3u'),
      );
      ?>
      <?php foreach ($urls as $label => $url): ?>
        <div class="url-row">
          <span class="url-label"><?= e($label) ?></span>
          <code class="url-code" id="url-<?= e(md5($url)) ?>"><?= e($url) ?></code>
          <button class="btn btn-sm copy-btn" type="button" data-copy="#url-<?= e(md5($url)) ?>">📋 Copiar</button>
        </div>
      <?php endforeach; ?>
      <p class="muted sm">Puedes ajustar los días de la guía añadiendo <code>&amp;days=N</code> (1–<?= EPGW_MAX_EXPORT_DAYS ?>) o filtrar canales con <code>&amp;channels=id1,id2</code>.</p>
    </div>
  <?php endif; ?>

  <form method="post" class="form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

    <label class="field"><span>Usuario</span>
      <input type="text" name="username" value="<?= e($edit['username'] ?? '') ?>" required pattern="[a-zA-Z0-9_.-]{3,30}"></label>

    <label class="field"><span>Contraseña <?= $edit ? '(vacío = no cambiar)' : '(mínimo 8)' ?></span>
      <input type="password" name="password" <?= $edit ? '' : 'required minlength="8"' ?>></label>

    <label class="field"><span>Rol</span>
      <select name="role">
        <option value="user" <?= (($edit['role'] ?? 'user') === 'user' ? 'selected' : '') ?>>Usuario (solo ve su guía y su acceso)</option>
        <option value="admin" <?= (($edit['role'] ?? '') === 'admin' ? 'selected' : '') ?>>Administrador (todo)</option>
      </select></label>

    <label class="field"><span>Vence el (opcional)</span>
      <input type="date" name="expires_at" value="<?= $edit && $edit['expires_at'] ? date('Y-m-d', (int)$edit['expires_at']) : '' ?>"></label>

    <label class="field"><span>Notas (opcional)</span>
      <input type="text" name="notes" value="<?= e($edit['notes'] ?? '') ?>" maxlength="200" placeholder="Ej: Cliente CableSur — paquete básico"></label>

    <label class="check"><input type="checkbox" name="is_active" <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>><span>Cuenta activa</span></label>

    <div class="field">
      <span>Canales visibles</span>
      <div class="radios">
        <label class="check"><input type="radio" name="access_mode" value="all" <?= (($edit['access_mode'] ?? 'all') !== 'selected') ? 'checked' : '' ?> onchange="document.getElementById('chpicker').style.display='none'"><span>Todos los canales</span></label>
        <label class="check"><input type="radio" name="access_mode" value="selected" <?= (($edit['access_mode'] ?? '') === 'selected') ? 'checked' : '' ?> onchange="document.getElementById('chpicker').style.display='block'"><span>Solo los seleccionados</span></label>
      </div>
    </div>

    <div id="chpicker" class="chpicker" style="<?= (($edit['access_mode'] ?? '') === 'selected') ? '' : 'display:none' ?>">
      <div class="chpicker-tools">
        <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('#chpicker input[type=checkbox]').forEach(c=>c.checked=true)">Marcar todos</button>
        <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('#chpicker input[type=checkbox]').forEach(c=>c.checked=false)">Desmarcar</button>
        <input type="text" class="chpicker-search" placeholder="Filtrar canales…" oninput="epgFilterChannels(this.value)">
      </div>
      <div class="chpicker-list">
        <?php $lastSrc = null; foreach ($allChannels as $c):
          if ($c['src_name'] !== $lastSrc) { $lastSrc = $c['src_name']; echo '<div class="chpicker-group">' . e($lastSrc ?? 'Sin fuente') . '</div>'; } ?>
          <label class="check ch-item" data-name="<?= e(mb_strtolower($c['display_name'] . ' ' . $c['xmltv_id'], 'UTF-8')) ?>">
            <input type="checkbox" name="channels[]" value="<?= (int)$c['id'] ?>" <?= isset($assigned[(int)$c['id']]) ? 'checked' : '' ?>>
            <span><?= e($c['display_name']) ?><?= $c['enabled'] ? '' : ' (oculto)' ?></span>
          </label>
        <?php endforeach; ?>
        <?php if (!$allChannels): ?><p class="muted">Aún no hay canales: importa una fuente primero.</p><?php endif; ?>
      </div>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= $edit ? 'Guardar cambios' : 'Crear usuario' ?></button>
      <?php if ($edit): ?><a class="btn" href="<?= e(u('usuarios')) ?>">Cancelar</a><?php endif; ?>
    </div>
  </form>
</section>
<?php page_footer(); ?>
