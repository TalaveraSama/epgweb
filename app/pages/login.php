<?php
/**
 * EPGWeb — Inicio de sesión.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

auth_start_session();

if (auth_user()) { redirect(u('dashboard')); }

$error = '';
$username = '';

if (is_post()) {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    list($ok, $res) = auth_login($username, $password);
    if ($ok) {
        flash('ok', 'Bienvenido, ' . $res['username'] . '.');
        redirect(u('dashboard'));
    }
    $error = $res;
}

$siteName = epg_setting('site_name', 'EPGWeb');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión · <?= e($siteName) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= EPGW_VERSION ?>">
</head>
<body class="auth-body">
  <div class="auth-card">
    <div class="auth-brand">
      <span class="brand-logo big">📡</span>
      <h1><?= e($siteName) ?></h1>
      <p class="muted">Panel de servicio EPG (XMLTV)</p>
    </div>
    <?php foreach (flash_get() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <label class="field">
        <span>Usuario</span>
        <input type="text" name="username" value="<?= e($username) ?>" required autofocus>
      </label>
      <label class="field">
        <span>Contraseña</span>
        <input type="password" name="password" required>
      </label>
      <?= csrf_field() ?>
      <button class="btn btn-primary btn-block" type="submit">Entrar</button>
    </form>
    <p class="auth-foot muted">EPGWeb <?= EPGW_VERSION ?></p>
  </div>
</body>
</html>
<?php
/* La sesión ya se inició en auth_start_session() */
