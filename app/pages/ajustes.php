<?php
/**
 * EPGWeb — Ajustes del servicio.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit; }

require_admin();

$tzChoices = array(
    'America/Managua', 'America/Costa_Rica', 'America/Tegucigalpa', 'America/El_Salvador',
    'America/Guatemala', 'America/Panama', 'America/Mexico_City', 'America/Bogota',
    'America/Lima', 'America/Santiago', 'America/Caracas', 'America/New_York',
    'America/Los_Angeles', 'America/Sao_Paulo', 'Europe/Madrid', 'UTC',
);

if (is_post()) {
    csrf_check();
    $name = trim((string)($_POST['site_name'] ?? ''));
    $tz   = (string)($_POST['timezone'] ?? '');
    $exportDays = max(1, min(EPGW_MAX_EXPORT_DAYS, (int)($_POST['export_days'] ?? EPGW_DEFAULT_EXPORT_DAYS)));
    $retention  = max(1, min(60, (int)($_POST['retention_days'] ?? 7)));
    if ($name === '') { $name = 'EPGWeb'; }
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) { $tz = EPGW_DEFAULT_TZ; }

    epg_setting_set('site_name', $name);
    epg_setting_set('timezone', $tz);
    epg_setting_set('export_days', (string)$exportDays);
    epg_setting_set('retention_days', (string)$retention);
    flash('ok', 'Ajustes guardados.');
    redirect(u('ajustes'));
}

page_header('Ajustes', 'ajustes');
?>
<section class="card form-narrow">
  <h2 class="card-title">⚙️ Ajustes del servicio</h2>
  <form method="post">
    <?= csrf_field() ?>
    <label class="field"><span>Nombre del servicio</span>
      <input type="text" name="site_name" value="<?= e(epg_setting('site_name', 'EPGWeb')) ?>" maxlength="60" required></label>

    <label class="field"><span>Zona horaria (para mostrar y exportar la guía)</span>
      <select name="timezone">
        <?php foreach ($tzChoices as $z): ?>
          <option value="<?= e($z) ?>" <?= (epg_site_tz() === $z ? 'selected' : '') ?>><?= e($z) ?></option>
        <?php endforeach; ?>
      </select></label>

    <label class="field"><span>Días de guía por defecto en la API (1–<?= EPGW_MAX_EXPORT_DAYS ?>)</span>
      <input type="number" name="export_days" min="1" max="<?= EPGW_MAX_EXPORT_DAYS ?>"
             value="<?= e(epg_setting('export_days', EPGW_DEFAULT_EXPORT_DAYS)) ?>"></label>

    <label class="field"><span>Días de retención de programas antiguos</span>
      <input type="number" name="retention_days" min="1" max="60"
             value="<?= e(epg_setting('retention_days', 7)) ?>"></label>

    <button class="btn btn-primary" type="submit">Guardar ajustes</button>
  </form>
  <hr class="sep">
  <p class="muted sm">Versión instalada: EPGWeb <?= EPGW_VERSION ?> · Instalado el <?= epg_fmt((int)epg_setting('installed_at', time()), 'd/m/Y') ?></p>
</section>
<?php page_footer(); ?>
