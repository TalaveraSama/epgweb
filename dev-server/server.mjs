#!/usr/bin/env node
/**
 * EPGWeb — Servidor de desarrollo: PHP real (WebAssembly) sin instalar PHP.
 *
 * Usa @php-wasm/node (PHP 8.3 compilado a WASM, con SQLite, XMLReader, etc.)
 * y sirve la carpeta `public/` del proyecto como si fuera un hosting PHP.
 * La base de datos y sesiones se guardan de verdad en `data/`.
 *
 *   npm install
 *   node server.mjs            (por defecto en el puerto 8080)
 *   PORT=9000 node server.mjs
 *
 * Solo para desarrollo y demos: en producción usa PHP normal
 * (php -S, Apache, Nginx+PHP-FPM, cPanel…). Ver README.md.
 */

import http from 'node:http';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const DOCROOT = path.resolve(HERE, '..', 'public');
const PORT = parseInt(process.env.PORT || '8080', 10);

console.log('[epgweb] Cargando PHP 8.3 (WebAssembly)…');
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
useHostFilesystem(php);

const handler = new PHPRequestHandler({
  php,
  documentRoot: DOCROOT,
  absoluteUrl: `http://127.0.0.1:${PORT}`,
  // CRÍTICO: sin esto, el handler recuerda cookies entre peticiones y mezcla
  // las sesiones de todos los visitantes como si fueran un solo navegador.
  cookieStore: false,
});

const server = http.createServer(async (req, res) => {
  const started = Date.now();
  try {
    const chunks = [];
    for await (const c of req) chunks.push(c);
    const body = Buffer.concat(chunks);

    const headers = { ...req.headers };
    delete headers.host;                    // el handler lo calcula de la URL
    delete headers.connection;
    delete headers['content-length'];       // se recalcula

    // URL absoluta con el Host que llegó (soporta el proxy de vista previa)
    const host = req.headers.host || `127.0.0.1:${PORT}`;
    const proto = (req.headers['x-forwarded-proto'] || 'http').split(',')[0].trim();
    const url = `${proto}://${host}${req.url}`;

    const response = await handler.request({
      method: req.method,
      url,
      headers,
      body: body.length ? body : undefined,
    });

    const out = Buffer.from(await response.bytes);
    const h = await response.headers;
    for (const [k, vals] of Object.entries(h)) {
      for (const v of Array.isArray(vals) ? vals : [vals]) {
        try { res.setHeader(k, v); } catch { /* cabecera inválida */ }
      }
    }
    res.statusCode = (await response.httpStatusCode) || 200;
    res.end(out);

    // stderr de PHP: muestra avisos/errores fatales en consola
    try {
      const errText = await response.stderrText;
      if (errText && errText.trim()) {
        console.error(`[epgweb] PHP ${req.method} ${req.url}:\n${errText.trim().split('\n').map(l => '    ' + l).join('\n')}`);
      }
    } catch { /* sin stderr */ }

    console.log(`[epgweb] ${req.method} ${req.url} → ${res.statusCode} (${out.length} B, ${Date.now() - started} ms)`);
  } catch (err) {
    console.error('[epgweb] ERROR puente:', err);
    if (!res.headersSent) {
      res.statusCode = 500;
      res.setHeader('Content-Type', 'text/plain; charset=utf-8');
      res.end('Error interno del servidor de desarrollo EPGWeb: ' + err.message);
    } else {
      res.end();
    }
  }
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[epgweb] Panel listo → http://localhost:${PORT}  (docroot: ${DOCROOT})`);
  console.log('[epgweb] La primera petición puede tardar unos segundos (arranque de PHP).');
});

process.on('SIGTERM', () => { console.log('[epgweb] SIGTERM, cerrando…'); server.close(() => process.exit(0)); });
process.on('SIGINT', () => { console.log('[epgweb] SIGINT, cerrando…'); server.close(() => process.exit(0)); });
