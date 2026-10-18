<?php
/**
 * EPGWeb — Importador de guías XMLTV hacia SQLite.
 *
 * Soporta: descarga por HTTP(S) (cURL o wrappers), archivo local, archivos .gz,
 * y parsing en streaming con XMLReader (bajo uso de memoria) con respaldo en
 * SimpleXML. Es compatible con guías generadas por Astra, iptv-org, WebGrab+Plus,
 * y cualquier XMLTV estándar.
 */

if (!defined('EPGWEB')) { http_response_code(403); exit('EPGWeb: acceso directo no permitido'); }

/**
 * Descarga (o copia) el contenido de una URL/ruta a un archivo destino.
 * @return array [ok(bool), bytes(int), message(string)]
 */
function epg_fetch_to_file($url, $dest) {
    $url = trim((string)$url);
    if ($url === '') { return array(false, 0, 'La fuente no tiene URL configurada.'); }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $isRemote = in_array($scheme, array('http', 'https'), true);

    if (!$isRemote) {
        // archivo local del servidor (útil con rsync/scp o en el mismo equipo)
        $path = ($scheme === 'file') ? substr($url, 7) : $url;
        if (!is_file($path)) { return array(false, 0, "El archivo local no existe: $path"); }
        $n = @copy($path, $dest);
        if (!$n) { return array(false, 0, 'No se pudo copiar el archivo local (¿permisos?).'); }
        @touch($dest);
        clearstatcache(true, $dest);
        return array(true, filesize($dest), "Archivo local importado: $path");
    }

    @set_time_limit(0);
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) { return array(false, 0, 'No se pudo crear el archivo temporal de descarga.'); }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 900,
            CURLOPT_USERAGENT      => 'EPGWeb/' . EPGW_VERSION . ' (+xmltv-importer)',
            CURLOPT_SSL_VERIFYPEER => false,   // fuentes IPTV internas suelen usar cert. autofirmado
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FAILONERROR    => true,
        ));
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok) {
            @unlink($dest);
            return array(false, 0, "Error HTTP al descargar (código $code): $err");
        }
        clearstatcache(true, $dest);
        return array(true, filesize($dest), 'Descargado por HTTP (cURL)');
    }

    // respaldo: wrappers de flujo de PHP
    $ctx = stream_context_create(array('http' => array(
        'timeout'        => 900,
        'follow_location' => 1,
        'max_redirects'  => 5,
        'user_agent'     => 'EPGWeb/' . EPGW_VERSION,
        'ignore_errors'  => false,
    ), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
    $n = @copy($url, $dest, $ctx);
    if (!$n) { return array(false, 0, 'No se pudo descargar la URL (sin cURL y los wrappers fallaron).'); }
    clearstatcache(true, $dest);
    return array(true, filesize($dest), 'Descargado por HTTP (stream wrappers)');
}

/** Si el archivo empieza con firma gzip, lo descomprime en su lugar. */
function epg_maybe_gunzip(&$file) {
    $f = @fopen($file, 'rb');
    if (!$f) { return; }
    $magic = fread($f, 2);
    fclose($f);
    if ($magic === "\x1f\x8b") {
        $out = $file . '.xml';
        $src = @gzopen($file, 'rb');
        $dst = @fopen($out, 'wb');
        if ($src && $dst) {
            while (!gzeof($src)) {
                $chunk = gzread($src, 262144);
                if ($chunk === false || $chunk === '') { break; }
                fwrite($dst, $chunk);
            }
            gzclose($src);
            fclose($dst);
            $file = $out;
        } else {
            if ($src) { gzclose($src); }
            if ($dst) { fclose($dst); }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Parsing XMLTV                                                      */
/* ------------------------------------------------------------------ */

/**
 * Extrae texto del primer hijo de un SimpleXMLElement.
 */
function _epg_child_str($el, $name, $default = '') {
    if (!isset($el->{$name})) { return $default; }
    $v = trim((string)$el->{$name}[0]);
    return ($v === '') ? $default : $v;
}

/**
 * Itera el XMLTV llamando $cbChannel(array) por canal y $cbProgramme(array) por programa.
 * @return array [ok, message, channels, programmes]
 */
function epg_parse_xmltv($file, $cbChannel, $cbProgramme) {
    $nCh = 0; $nPr = 0;

    // --- Estrategia 1: XMLReader (streaming, baja memoria) + DOM para subárboles ---
    if (class_exists('XMLReader')) {
        $reader = new XMLReader();
        $opened = @$reader->open($file, 'UTF-8', LIBXML_NONET);
        if ($opened) {
            $firstChild = function ($el, $name) {
                if (!$el || !$el->hasChildNodes()) { return null; }
                foreach ($el->childNodes as $c) {
                    if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === $name) { return $c; }
                }
                return null;
            };
            $childText = function ($el, $name) use ($firstChild) {
                $c = $firstChild($el, $name);
                return $c ? trim($c->textContent) : '';
            };
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) { continue; }
                $tag = $reader->localName;
                if ($tag === 'channel') {
                    $id = (string)$reader->getAttribute('id');
                    if ($id === '') { continue; }
                    $el = @$reader->expand();
                    if ($el === false || $el === null) { continue; }
                    $names = array();
                    if ($el->hasChildNodes()) {
                        foreach ($el->childNodes as $c) {
                            if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'display-name') {
                                $names[] = trim($c->textContent);
                            }
                        }
                    }
                    $icon = '';
                    $ic = $firstChild($el, 'icon');
                    if ($ic && $ic->hasAttribute('src')) { $icon = trim($ic->getAttribute('src')); }
                    $cbChannel(array(
                        'xmltv_id'     => $id,
                        'display_name' => $names ? $names[0] : $id,
                        'names'        => $names,
                        'icon'         => $icon,
                        'url'          => $childText($el, 'url'),
                    ));
                    $nCh++;
                } elseif ($tag === 'programme') {
                    $chId  = (string)$reader->getAttribute('channel');
                    $start = (string)$reader->getAttribute('start');
                    if ($chId === '' || $start === '') { continue; }
                    $el = @$reader->expand();
                    if ($el === false || $el === null) { continue; }
                    $icon = '';
                    $ic = $firstChild($el, 'icon');
                    if ($ic && $ic->hasAttribute('src')) { $icon = trim($ic->getAttribute('src')); }
                    $cbProgramme(array(
                        'xmltv_id'  => $chId,
                        'start_raw' => $start,
                        'stop_raw'  => (string)$reader->getAttribute('stop'),
                        'title'     => $childText($el, 'title'),
                        'sub_title' => $childText($el, 'sub-title'),
                        'descr'     => $childText($el, 'desc'),
                        'category'  => $childText($el, 'category'),
                        'country'   => $childText($el, 'country'),
                        'episode'   => $childText($el, 'episode-num'),
                        'icon'      => $icon,
                    ));
                    $nPr++;
                }
            }
            $reader->close();
            return array(true, 'XMLReader', $nCh, $nPr);
        }
        $reader->close();
    }

    // --- Estrategia 2: SimpleXML completo (requiere cargar todo en memoria) ---
    if (function_exists('simplexml_load_file')) {
        $sx = @simplexml_load_file($file);
        if ($sx === false) { $sx = @simplexml_load_string(file_get_contents($file)); }
        if ($sx !== false) {
            foreach ($sx->channel as $ch) {
                $names = array();
                foreach ($ch->{'display-name'} as $dn) { $names[] = trim((string)$dn); }
                $icon = '';
                foreach ($ch->icon as $ic) {
                    $s = trim((string)$ic['src']);
                    if ($s !== '') { $icon = $s; break; }
                }
                $cbChannel(array(
                    'xmltv_id'     => (string)$ch['id'],
                    'display_name' => $names ? $names[0] : (string)$ch['id'],
                    'names'        => $names,
                    'icon'         => $icon,
                    'url'          => _epg_child_str($ch, 'url'),
                ));
                $nCh++;
            }
            foreach ($sx->programme as $pr) {
                $cbProgramme(array(
                    'xmltv_id'  => (string)$pr['channel'],
                    'start_raw' => (string)$pr['start'],
                    'stop_raw'  => (string)$pr['stop'],
                    'title'     => _epg_child_str($pr, 'title'),
                    'sub_title' => _epg_child_str($pr, 'sub-title'),
                    'descr'     => _epg_child_str($pr, 'desc'),
                    'category'  => _epg_child_str($pr, 'category'),
                    'country'   => _epg_child_str($pr, 'country'),
                    'episode'   => _epg_child_str($pr, 'episode-num'),
                    'icon'      => (isset($pr->icon) && isset($pr->icon['src'])) ? trim((string)$pr->icon['src']) : '',
                ));
                $nPr++;
            }
            return array(true, 'SimpleXML', $nCh, $nPr);
        }
    }

    return array(false, 'No se pudo leer el XML (¿formato inválido o sin extensión XML?).', 0, 0);
}

/* ------------------------------------------------------------------ */
/* Importación completa de una fuente                                 */
/* ------------------------------------------------------------------ */

/** Registra un refresco en el historial y actualiza la fuente. */
function epg_record_refresh(PDO $db, $sourceId, $status, $channels, $programmes, $message, $t0) {
    $now = time();
    $db->prepare('INSERT INTO refreshes (source_id, started_at, finished_at, status, channels, programmes, message)
                  VALUES (?,?,?,?,?,?,?)')
       ->execute(array((int)$sourceId, (int)$t0, $now, $status, (int)$channels, (int)$programmes, substr((string)$message, 0, 500)));
    $db->prepare('UPDATE sources SET last_refresh = ?, last_status = ?, last_message = ?,
                  last_channels = ?, last_programmes = ? WHERE id = ?')
       ->execute(array($now, $status, substr((string)$message, 0, 500), (int)$channels, (int)$programmes, (int)$sourceId));
}

/**
 * Importa (o re-importa) una fuente completa de forma atómica:
 * si algo falla, la guía anterior queda intacta.
 *
 * @param PDO    $db
 * @param int    $sourceId id de la fuente
 * @param string|null $localFile si se pasa, se usa este archivo en lugar de descargar la URL
 * @return array resumen: ok(bool), message, channels, programmes, seconds
 */
function epg_import_source(PDO $db, $sourceId, $localFile = null) {
    $t0 = microtime(true);
    $sourceId = (int)$sourceId;
    $st = $db->prepare('SELECT * FROM sources WHERE id = ?');
    $st->execute(array($sourceId));
    $source = $st->fetch();
    if (!$source) {
        return array('ok' => false, 'message' => 'Fuente no encontrada.', 'channels' => 0, 'programmes' => 0, 'seconds' => 0);
    }

    @set_time_limit(EPGW_IMPORT_TIME_LIMIT);
    if (function_exists('ignore_user_abort')) { ignore_user_abort(true); }

    $isUploaded = ($localFile !== null);
    $rawFile = $isUploaded ? $localFile : EPGW_DATA . '/cache/source_' . $sourceId . '.xml';

    // 1. obtener el XML
    if ($isUploaded) {
        if (!is_file($rawFile)) {
            return array('ok' => false, 'message' => 'El archivo a importar no existe.', 'channels' => 0, 'programmes' => 0, 'seconds' => 0);
        }
        $bytes = filesize($rawFile);
        $fetchMsg = 'Archivo local/subido';
    } else {
        $f = epg_fetch_to_file($source['url'], $rawFile);
        if (!$f[0]) {
            epg_record_refresh($db, $sourceId, 'error', 0, 0, $f[2], $t0);
            return array('ok' => false, 'message' => $f[2], 'channels' => 0, 'programmes' => 0, 'seconds' => 0);
        }
        $bytes = $f[1];
        $fetchMsg = $f[2];
    }

    // 2. descomprimir si viene .gz
    $xmlFile = $rawFile;
    epg_maybe_gunzip($xmlFile);

    // 3. transacción: borrar guía previa de la fuente e insertar la nueva
    $nCh = 0; $nPr = 0;
    try {
        $db->beginTransaction();

        // guías de esta fuente fuera (la nueva se inserta en el mismo txn)
        $db->prepare('DELETE FROM programmes WHERE source_id = ?')->execute(array($sourceId));

        $insCh = $db->prepare(<<<'SQL'
INSERT INTO channels (source_id, xmltv_id, display_name, icon, url, enabled, programme_count, first_start, last_stop)
VALUES (?,?,?,?,?,1,0,NULL,NULL)
ON CONFLICT(source_id, xmltv_id) DO UPDATE SET
  display_name = excluded.display_name,
  icon = CASE WHEN excluded.icon <> '' THEN excluded.icon ELSE channels.icon END,
  url  = excluded.url
SQL
        );
        $insPr = $db->prepare('INSERT INTO programmes (source_id, xmltv_id, starts_at, ends_at, title, sub_title, descr, category, country, episode, icon)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?)');

        $seenChannels = array(); // xmltv_id => display_name

        $res = epg_parse_xmltv($xmlFile,
            // canal
            function ($ch) use ($insCh, $sourceId, &$nCh, &$seenChannels) {
                if ($ch['xmltv_id'] === '' || isset($seenChannels[$ch['xmltv_id']])) { return; }
                $insCh->execute(array(
                    $sourceId, $ch['xmltv_id'],
                    ($ch['display_name'] !== '') ? $ch['display_name'] : $ch['xmltv_id'],
                    $ch['icon'], $ch['url'],
                ));
                $seenChannels[$ch['xmltv_id']] = true;
                $nCh++;
            },
            // programa
            function ($pr) use ($insPr, $sourceId, &$nPr) {
                $start = epg_parse_xmltv_time($pr['start_raw']);
                if ($start === null || $pr['title'] === '') { return; }
                $stop = epg_parse_xmltv_time($pr['stop_raw']);
                if ($stop === null) { $stop = $start + 1800; }
                if ($stop <= $start) { $stop = $start + 300; }
                $insPr->execute(array(
                    $sourceId, $pr['xmltv_id'], $start, $stop,
                    $pr['title'], $pr['sub_title'], $pr['descr'], $pr['category'],
                    $pr['country'], $pr['episode'], $pr['icon'],
                ));
                $nPr++;
            }
        );

        if (!$res[0]) {
            $db->rollBack();
            epg_record_refresh($db, $sourceId, 'error', 0, 0, $res[1], $t0);
            return array('ok' => false, 'message' => $res[1], 'channels' => 0, 'programmes' => 0,
                         'seconds' => round(microtime(true) - $t0, 1));
        }

        // 4. recalcular estadísticas por canal
        $agg = $db->prepare('SELECT xmltv_id, COUNT(*) AS n, MIN(starts_at) AS f, MAX(ends_at) AS l
                             FROM programmes WHERE source_id = ? GROUP BY xmltv_id');
        $agg->execute(array($sourceId));
        $upd = $db->prepare('UPDATE channels SET programme_count = ?, first_start = ?, last_stop = ?
                             WHERE source_id = ? AND xmltv_id = ?');
        foreach ($agg->fetchAll() as $row) {
            $upd->execute(array((int)$row['n'], (int)$row['f'], (int)$row['l'], $sourceId, $row['xmltv_id']));
        }
        // canales definidos sin programación quedan en 0
        $db->prepare('UPDATE channels SET programme_count = 0, first_start = NULL, last_stop = NULL
                      WHERE source_id = ? AND xmltv_id NOT IN (SELECT DISTINCT xmltv_id FROM programmes WHERE source_id = ?)')
           ->execute(array($sourceId, $sourceId));

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        $msg = 'Error importando: ' . $e->getMessage();
        epg_record_refresh($db, $sourceId, 'error', 0, 0, $msg, $t0);
        return array('ok' => false, 'message' => $msg, 'channels' => 0, 'programmes' => 0,
                     'seconds' => round(microtime(true) - $t0, 1));
    }

    // limpiar temporales (solo lo que descargamos; las subidas se guardan aparte)
    if (!$isUploaded) {
        @unlink($rawFile);
        if ($xmlFile !== $rawFile) { @unlink($xmlFile); }
    }

    // 5. poda de programas viejos (retención configurada, mínimo 1 día)
    $retention = max(1, (int)epg_setting('retention_days', 7));
    try {
        $db->prepare('DELETE FROM programmes WHERE ends_at < ?')->execute(array(time() - $retention * 86400));
    } catch (Exception $e) { /* no crítico */ }

    // 6. registrar resultado
    $msg = sprintf('%s — %d canales, %d programas, %s recibidos', $fetchMsg, $nCh, $nPr, epg_human_bytes($bytes));
    epg_record_refresh($db, $sourceId, 'ok', $nCh, $nPr, $msg, $t0);

    return array('ok' => true, 'message' => $msg, 'channels' => $nCh, 'programmes' => $nPr,
                 'seconds' => round(microtime(true) - $t0, 1));
}

/**
 * Guarda un archivo subido ($_FILES) y devuelve la ruta temporal.
 * @return array [ok, path o mensaje]
 */
function epg_store_upload(array $file) {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return array(false, 'La subida del archivo falló (código ' . ($file['error'] ?? '?') . ').');
    }
    if (($file['size'] ?? 0) <= 0) { return array(false, 'El archivo subido está vacío.'); }
    $dest = EPGW_DATA . '/uploads/import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xml';
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        // en algunos entornos (CLI/dev) move_uploaded_file falla; probar rename
        if (!@rename($file['tmp_name'], $dest)) {
            return array(false, 'No se pudo guardar el archivo subido.');
        }
    }
    return array(true, $dest);
}

/** Elimina una fuente y todo su contenido (canales, programas, asignaciones). */
function epg_delete_source(PDO $db, $sourceId) {
    $sourceId = (int)$sourceId;
    $db->beginTransaction();
    $db->exec('DELETE FROM programmes WHERE source_id = ' . $sourceId);
    $db->exec('DELETE FROM user_channels WHERE channel_id IN (SELECT id FROM channels WHERE source_id = ' . $sourceId . ')');
    $db->exec('DELETE FROM channels WHERE source_id = ' . $sourceId);
    $db->exec('DELETE FROM refreshes WHERE source_id = ' . $sourceId);
    $db->exec('DELETE FROM sources WHERE id = ' . $sourceId);
    $db->commit();
}
