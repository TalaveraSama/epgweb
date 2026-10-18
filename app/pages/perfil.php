<?php
/**
 * EPGWeb — Perfil: cambiar mi contraseña.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_login();
$user = auth_user();
$db = epg_db();

if (is_post()) {
    csrf_check();
    $cur   = (string)($_POST['current'] ?? '');
    $new   = (string)($_POST['new'] ?? '');
    $new2  = (string)($_POST['new2'] ?? '');
    if (!password_verify($cur, $user['password_hash'])) {
        flash('err', 'La contraseña actual no es correcta.');
    } elseif (strlen($new) < 8) {
        flash('err', 'La nueva contraseña debe tener al menos 8 caracteres.');
    } elseif ($new !== $new2) {
        flash('err', 'Las contraseñas nuevas no coinciden.');
    } else {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute(array(password_hash($new, PASSWORD_DEFAULT), (int)$user['id']));
        flash('ok', 'Contraseña actualizada.');
    }
    redirect(u('perfil'));
}

page_header('Mi perfil', 'perfil');
?>
<section class="card form-narrow">
  <h2 class="card-title">👤 Cambiar contraseña</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label class="field"><span>Contraseña actual</span>
      <input type="password" name="current" required></label>
    <label class="field"><span>Nueva contraseña (mínimo 8)</span>
      <input type="password" name="new" required minlength="8"></label>
    <label class="field"><span>Repetir nueva contraseña</span>
      <input type="password" name="new2" required minlength="8"></label>
    <button class="btn btn-primary" type="submit">Actualizar contraseña</button>
  </form>
  <hr class="sep">
  <p class="muted sm">Usuario: <strong><?= e($user['username']) ?></strong> ·
  Rol: <?= $user['role'] === 'admin' ? 'administrador' : 'usuario' ?> ·
  Creado: <?= epg_fmt_date($user['created_at']) ?></p>
</section>
<?php page_footer(); ?>
