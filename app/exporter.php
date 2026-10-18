<?php
/**
 * EPGWeb — Exportación de la guía por usuario: XMLTV, JSON y M3U.
 *
 * Cada usuario del panel entrega su guía filtrada por los canales que
 * tiene asignados; la clave de API viaja en la URL (?k=...).
 */

if (!defined('EPGWEB')) { http_response_code(403); exit('EPGWeb: acceso directo no permitido'); }

/**
 * Resuelve la lista de canales a exportar para un usuario,
 * opcionalmente filtrada por xmltv_ids pedidos en la URL.
 * @return array filas de canales (con source_id y xmltv_id)
 */
function epg_export_channels($user, $filterIds = null) {
    $db = epg_db();
    if ($user['access_mode'] === 'all') {
        $rows = $db->query('SELECT * FROM channels WHERE enabled = 1 ORDER BY display_name COLLATE NOCASE')->fetchAll();
    } else {
        $st = $db->prepare('SELECT c.* FROM user_channels uc
                            JOIN channels c ON c.id = uc.channel_id
                            WHERE uc.user_id = ? AND c.enabled = 1
                            ORDER BY c.display_name COLLATE NOCASE');
        $st->execute(array((int)$user['id']));
        $rows = $st->fetchAll();
    }
    if ($filterIds !== null && $filterIds !== array()) {
        $want = array();
        foreach ((array)$filterIds as $id) { $want[strtolower(trim($id))] = true; }
        $rows = array_values(array_filter($rows, function ($c) use ($want) {
            return isset($want[strtolower($c['xmltv_id'])]);
        }));
    }
    return $rows;
}

/* ------------------------------------------------------------------ */
/* XMLTV                                                              */
/* ------------------------------------------------------------------ */

/**
 * Genera y envía un XMLTV con los canales/programas indicados.
 * @return int bytes enviados
 */
function epg_export_xmltv(array $channels, $fromTs, $toTs, $user) {
    $db = epg_db();
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: inline; filename="epgweb.xml"');

    $x = new XMLWriter();
    $x->openMemory();
    $x->setIndent(true);
    $x->startDocument('1.0', 'UTF-8');

    $x->startElement('tv');
    $x->writeAttribute('generator-info-name', 'EPGWeb ' . EPGW_VERSION);
    $x->writeAttribute('source-info-name', 'EPGWeb — ' . epg_setting('site_name', 'EPGWeb'));

    $selPr = $db->prepare('SELECT * FROM programmes
                           WHERE source_id = ? AND xmltv_id = ? AND starts_at < ? AND ends_at > ?
                           ORDER BY starts_at');
    $bytes = 0;
    $emit = function ($x) use (&$bytes) {
        $chunk = $x->flush(true);
        $bytes += strlen($chunk);
        echo $chunk;
    };

    // canales
    foreach ($channels as $c) {
        $x->startElement('channel');
        $x->writeAttribute('id', $c['xmltv_id']);
        $x->startElement('display-name');
        $x->writeAttribute('lang', 'es');
        $x->text($c['display_name']);
        $x->endElement();
        if ($c['icon'] !== '') {
            $x->startElement('icon');
            $x->writeAttribute('src', $c['icon']);
            $x->endElement();
        }
        if ($c['url'] !== '') {
            $x->writeElement('url', $c['url']);
        }
        $x->endElement(); // channel
        $emit($x);
    }

    // programas
    foreach ($channels as $c) {
        $selPr->execute(array($c['source_id'], $c['xmltv_id'], $toTs, $fromTs));
        while ($pr = $selPr->fetch()) {
            $x->startElement('programme');
            $x->writeAttribute('start',  epg_fmt_xmltv_time($pr['starts_at']));
            $x->writeAttribute('stop',   epg_fmt_xmltv_time($pr['ends_at']));
            $x->writeAttribute('channel', $c['xmltv_id']);
            if ($pr['icon'] !== '') {
                $x->startElement('icon');
                $x->writeAttribute('src', $pr['icon']);
                $x->endElement();
            }
            $x->startElement('title');
            $x->writeAttribute('lang', 'es');
            $x->text($pr['title']);
            $x->endElement();
            if ($pr['sub_title'] !== '') {
                $x->startElement('sub-title');
                $x->writeAttribute('lang', 'es');
                $x->text($pr['sub_title']);
                $x->endElement();
            }
            if ($pr['descr'] !== '') {
                $x->startElement('desc');
                $x->writeAttribute('lang', 'es');
                $x->text($pr['descr']);
                $x->endElement();
            }
            if ($pr['category'] !== '') {
                $x->startElement('category');
                $x->writeAttribute('lang', 'es');
                $x->text($pr['category']);
                $x->endElement();
            }
            if ($pr['country'] !== '') {
                $x->writeElement('country', $pr['country']);
            }
            if ($pr['episode'] !== '') {
                $x->startElement('episode-num');
                $x->writeAttribute('system', 'onscreen');
                $x->text($pr['episode']);
                $x->endElement();
            }
            $x->endElement(); // programme
            $emit($x);
        }
    }

    $x->endElement(); // tv
    $emit($x);

    return $bytes;
}

/* ------------------------------------------------------------------ */
/* JSON                                                               */
/* ------------------------------------------------------------------ */

/**
 * Genera y envía la guía en JSON.
 * @return int bytes
 */
function epg_export_json(array $channels, $fromTs, $toTs, $user) {
    $db = epg_db();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: inline; filename="epgweb.json"');

    $bytes = 0;
    $emit = function ($s) use (&$bytes) { $bytes += strlen($s); echo $s; };

    $emit('{"generator":"EPGWeb ' . EPGW_VERSION . '","generated":' . time() . ',"timezone":' .
          json_encode(epg_site_tz()) . ',"channels":[');
    $first = true;
    $chanIndex = array();
    foreach ($channels as $c) {
        $chanIndex[$c['source_id'] . '|' . $c['xmltv_id']] = $c;
        $emit(($first ? '' : ',') . json_encode(array(
            'id'    => $c['xmltv_id'],
            'name'  => $c['display_name'],
            'icon'  => $c['icon'] ?: null,
        )));
        $first = false;
    }
    $emit('],"programmes":[');

    $selPr = $db->prepare('SELECT * FROM programmes
                           WHERE source_id = ? AND xmltv_id = ? AND starts_at < ? AND ends_at > ?
                           ORDER BY starts_at');
    $first = true;
    foreach ($channels as $c) {
        $selPr->execute(array($c['source_id'], $c['xmltv_id'], $toTs, $fromTs));
        while ($pr = $selPr->fetch()) {
            $row = array(
                'channel' => $c['xmltv_id'],
                'start'   => (int)$pr['starts_at'],
                'stop'    => (int)$pr['ends_at'],
                'start_fmt' => epg_fmt_xmltv_time($pr['starts_at']),
                'stop_fmt'  => epg_fmt_xmltv_time($pr['ends_at']),
                'title'   => $pr['title'],
            );
            if ($pr['sub_title'] !== '') { $row['sub_title'] = $pr['sub_title']; }
            if ($pr['descr'] !== '')     { $row['desc'] = $pr['descr']; }
            if ($pr['category'] !== '')  { $row['category'] = $pr['category']; }
            if ($pr['episode'] !== '')   { $row['episode'] = $pr['episode']; }
            $emit(($first ? '' : ',') . json_encode($row));
            $first = false;
        }
    }
    $emit(']}');
    return $bytes;
}

/* ------------------------------------------------------------------ */
/* M3U (lista con tvg-id para reproductores)                           */
/* ------------------------------------------------------------------ */

/**
 * Genera una lista M3U de los canales asignados, con tvg-id que coincide
 * con los ids del XMLTV exportado (útil para mapear EPG en reproductores).
 * @return int bytes
 */
function epg_export_m3u(array $channels, $user) {
    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: inline; filename="epgweb.m3u"');

    $bytes = 0;
    $emit = function ($s) use (&$bytes) { $bytes += strlen($s); echo $s; };

    $epgUrl = epg_api_url($user['api_key'], 'xml');
    $emit("#EXTM3U x-tvg-url=\"" . $epgUrl . "\" url-tvg=\"" . $epgUrl . "\"\n");
    foreach ($channels as $c) {
        $attrs = 'tvg-id="' . $c['xmltv_id'] . '" tvg-name="' . $c['display_name'] . '"';
        if ($c['icon'] !== '') { $attrs .= ' tvg-logo="' . $c['icon'] . '"'; }
        $attrs .= ' group-title="EPGWeb"';
        $emit('#EXTINF:-1 ' . $attrs . ',' . $c['display_name'] . "\n");
        $emit(($c['url'] !== '' ? $c['url'] : '#') . "\n");
    }
    return $bytes;
}

/* ------------------------------------------------------------------ */
/* Utilidades para el panel                                           */
/* ------------------------------------------------------------------ */

/**
 * Programa "en emisión" de un canal ahora mismo.
 * @return array|null
 */
function epg_now_playing($sourceId, $xmltvId) {
    $now = time();
    $st = epg_db()->prepare('SELECT * FROM programmes
                             WHERE source_id = ? AND xmltv_id = ? AND starts_at <= ? AND ends_at > ?
                             ORDER BY starts_at DESC LIMIT 1');
    $st->execute(array($sourceId, $xmltvId, $now, $now));
    $r = $st->fetch();
    return $r ?: null;
}

/** Próximos N programas de un canal desde un momento dado. */
function epg_upcoming($sourceId, $xmltvId, $fromTs = null, $limit = 10) {
    $st = epg_db()->prepare('SELECT * FROM programmes
                             WHERE source_id = ? AND xmltv_id = ? AND ends_at > ?
                             ORDER BY starts_at LIMIT ' . max(1, (int)$limit));
    $st->execute(array($sourceId, $xmltvId, $fromTs ?: time()));
    return $st->fetchAll();
}

/** Programas de un canal dentro de una ventana (día seleccionado). */
function epg_programmes_between($sourceId, $xmltvId, $fromTs, $toTs) {
    $st = epg_db()->prepare('SELECT * FROM programmes
                             WHERE source_id = ? AND xmltv_id = ? AND starts_at < ? AND ends_at > ?
                             ORDER BY starts_at');
    $st->execute(array($sourceId, $xmltvId, $toTs, $fromTs));
    return $st->fetchAll();
}
