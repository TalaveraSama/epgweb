#!/usr/bin/env php
<?php
/**
 * EPGWeb — Refresco de fuentes EPG por CLI (cron).
 *
 * Uso:
 *   php cron/refresh.php            → actualiza todas las fuentes activas
 *   php cron/refresh.php all        → ídem
 *   php cron/refresh.php 3          → actualiza solo la fuente con id 3
 *
 * Ejemplo de crontab (cada 6 horas):
 *   0 0,6,12,18 * * *  php /ruta/epgweb/cron/refresh.php all >> /var/log/epgweb.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse por línea de comandos (CLI).\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$target = $argv[1] ?? 'all';

$db = epg_db();

if ($target === 'all' || $target === '') {
    $sources = $db->query('SELECT id, name, url FROM sources WHERE enabled = 1 ORDER BY id')->fetchAll();
} else {
    $st = $db->prepare('SELECT id, name, url FROM sources WHERE id = ?');
    $st->execute(array((int)$target));
    $sources = $st->fetchAll();
    if (!$sources) {
        fwrite(STDERR, "No existe la fuente con id {$target}.\n");
        exit(1);
    }
}

if (!$sources) {
    echo "No hay fuentes activas para actualizar.\n";
    exit(0);
}

$fail = 0;
foreach ($sources as $s) {
    echo '[' . date('H:i:s') . "] Actualizando «{$s['name']}» (#{$s['id']}) desde {$s['url']}…\n";
    $res = epg_import_source($db, (int)$s['id']);
    echo ($res['ok'] ? '  ✔ ' : '  ✘ ') . $res['message'] . ' (' . $res['seconds'] . " s)\n";
    if (!$res['ok']) { $fail++; }
}

// poda de accesos viejos (> 60 días)
try {
    $db->exec('DELETE FROM accesses WHERE created_at < ' . (time() - 60 * 86400));
} catch (Exception $e) { /* ignorar */ }

exit($fail ? 1 : 0);
