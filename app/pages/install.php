<?php
/**
 * EPGWeb — Instalador inicial (crea BD, ajustes y cuenta administradora).
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

if (epg_installed()) {
    flash('info', 'La aplicación ya está instalada. Inicia sesión.');
    redirect(u('login'));
}

$errors = array();
$siteName = 'EPGWeb';
$tz = EPGW_DEFAULT_TZ;
$adminUser = '';

$tzChoices = array(
    'America/Managua', 'America/Costa_Rica', 'America/Tegucigalpa', 'America/El_Salvador',
    'America/Guatemala', 'America/Panama', 'America/Mexico_City', 'America/Bogota',
    'America/Lima', 'America/Santiago', 'America/Caracas', 'America/New_York',
    'America/Los_Angeles', 'America/Sao_Paulo', 'Europe/Madrid', 'UTC',
);

if (is_post()) {
    csrf_check();
    $siteName   = trim($_POST['site_name'] ?? 'EPGWeb');
    $tz         = trim($_POST['timezone'] ?? EPGW_DEFAULT_TZ);
    $adminUser  = trim($_POST['admin_user'] ?? '');
    $adminPass  = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
    $addDefault = isset($_POST['add_default_source']);

    if ($siteName === '' )                    { $errors[] = 'Escribe un nombre para el servicio.'; }
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $adminUser)) {
        $errors[] = 'El usuario administrador debe tener 3–30 caracteres (letras, números, . _ -).';
    }
    if (strlen($adminPass) < 8)               { $errors[] = 'La contraseña debe tener al menos 8 caracteres.'; }
    if ($adminPass !== $adminPass2)           { $errors[] = 'Las contraseñas no coinciden.'; }
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) { $tz = EPGW_DEFAULT_TZ; }

    if (!$errors) {
        $db = epg_db();
        epg_db_install($db);
        epg_setting_set('site_name', $siteName);
        epg_setting_set('timezone', $tz);
        epg_setting_set('export_days', (string)EPGW_DEFAULT_EXPORT_DAYS);
        epg_setting_set('retention_days', '7');
        epg_setting_set('installed_at', (string)time());

        $st = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute(array($adminUser));
        if ($st->fetchColumn() > 0) {
            $errors[] = 'Ese nombre de usuario ya existe.';
        } else {
            $db->prepare('INSERT INTO users (username, password_hash, role, api_key, access_mode, is_active, created_at)
                          VALUES (?,?,?,?,?,?,?)')
               ->execute(array(
                   $adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'admin',
                   epg_generate_api_key(), 'all', 1, time(),
               ));

            // fuente EPG inicial (el servidor Astra del cliente), lista para actualizar
            if ($addDefaultSource) {
                $db->prepare('INSERT INTO sources (name, url, enabled, created_at) VALUES (?,?,1,?)')
                   ->execute(array(EPGW_DEFAULT_SOURCE_NAME, EPGW_DEFAULT_SOURCE_URL, time()));
            }

            flash('ok', '¡Instalación completada! Ya puedes iniciar sesión.');
            redirect(u('login'));
        }
    }
}

page_header('Instalación', 'install');
?>
<div class="login-wrap">
  <form method="post" class="card form-narrow" autocomplete="off">
    <h2 class="card-title">🚀 Instalación de EPGWeb</h2>
    <p class="muted">Primer uso: crea la cuenta de administrador del panel.</p>
    <?php foreach ($errors as $er): ?><div class="flash flash-err"><?= e($er) ?></div><?php endforeach; ?>

    <label class="field">
      <span>Nombre del servicio</span>
      <input type="text" name="site_name" value="<?= e($siteName) ?>" required maxlength="60">
    </label>

    <label class="field">
      <span>Zona horaria</span>
      <select name="timezone">
        <?php foreach ($tzChoices as $z): ?>
          <option value="<?= e($z) ?>" <?= ($z === $tz ? 'selected' : '') ?>><?= e($z) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <hr class="sep">

    <label class="field">
      <span>Usuario administrador</span>
      <input type="text" name="admin_user" value="<?= e($adminUser) ?>" required pattern="[a-zA-Z0-9_.-]{3,30}">
    </label>
    <label class="field">
      <span>Contraseña (mínimo 8)</span>
      <input type="password" name="admin_pass" required minlength="8">
    </label>
    <label class="field">
      <span>Repetir contraseña</span>
      <input type="password" name="admin_pass2" required minlength="8">
    </label>

    <label class="check">
      <input type="checkbox" name="add_default_source" checked>
      <span>Crear la fuente EPG inicial <code><?= e(EPGW_DEFAULT_SOURCE_URL) ?></code></span>
    </label>

    <?= csrf_field() ?>
    <button class="btn btn-primary btn-block" type="submit">Instalar ahora</button>
  </form>
</div>
<?php
page_footer();
