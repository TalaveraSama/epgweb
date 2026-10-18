# 📡 EPGWeb — Panel de servicio EPG (XMLTV) en PHP

Panel web en **PHP puro** (sin frameworks) para administrar guías de programación
**EPG/XMLTV**, importarlas desde servidores como **Astra**, y **dar acceso**
a cada cliente/usuario a su propia guía filtrada mediante claves de API.

Pensado para proveedores IPTV: conectas tu fuente EPG (por ejemplo tu servidor
Astra), importas la guía, creas usuarios, les asignas canales y les entregas
una URL lista para pegar en cualquier reproductor (TiviMate, IPTV Smarters,
Kodi, apps propias…).

---

## ✨ Características

- **Instalador web** en español (crea base de datos y cuenta administradora).
- **Fuentes EPG**: importa XMLTV por **URL HTTP(S)** (soporta `.gz`), por
  **archivo subido** o por **ruta local** del servidor. Parsing en *streaming*
  con XMLReader → maneja guías grandes con poca memoria.
- **Panel de administración**: fuentes, catálogo de canales (ocultar/mostrar),
  navegador de guía (por día y «ahora en emisión»), usuarios, registros de
  acceso y ajustes.
- **Usuarios con acceso**:
  - rol `admin` (todo) y rol `usuario` (solo ve su guía y su enlace).
  - canales asignables por usuario (o todos).
  - fecha de vencimiento opcional, activar/desactivar, notas.
  - **clave de API personal** con URL XMLTV / JSON / M3U lista para copiar.
- **API de guía** robusta: filtrado por canales, días (1–14), gzip opcional,
  registro de uso (peticiones, tráfico, IP, agente).
- **Seguridad**: contraseñas con `password_hash`, sesiones HttpOnly + SameSite,
  token CSRF en todos los formularios, consultas preparadas (PDO), límite de
  intentos de login, cabeceras de seguridad, `data/` fuera del docroot.
- **Cron** incluido para refrescos automáticos; importación atómica (si algo
  falla, la guía anterior queda intacta).
- Sin dependencias de Composer: solo PHP + SQLite.

## 📋 Requisitos

| Componente | Mínimo | Notas |
|---|---|---|
| PHP | 7.4 (recomendado 8.x) | probado en 8.3 |
| Extensiones | `pdo_sqlite`, `sqlite3`, `xmlreader`/`dom`, `mbstring` o `iconv`, `json` | `curl` recomendado (si no, usa wrappers de flujo) |
| Escritura | carpeta `data/` | base de datos, sesiones, caché |
| Servidor web | Apache/Nginx/cPanel o `php -S` | el docroot debe ser `public/` |

## 📁 Estructura

```
epgweb/
├── public/            ← RAÍZ WEB (docroot). Solo esto debe ser público
│   ├── index.php      ← panel (rutas ?r=…)
│   ├── api.php        ← endpoint de guía para reproductores
│   ├── assets/        ← CSS/JS/favicon
│   └── .htaccess
├── app/               ← código del panel (no público)
│   ├── config.php     ← constantes (fuente EPG por defecto, zona horaria…)
│   ├── db.php         ← PDO SQLite + esquema
│   ├── helpers.php    ← fechas XMLTV, CSRF, URLs…
│   ├── auth.php       ← sesiones, roles, claves de API
│   ├── importer.php   ← descarga + parseo XMLTV → SQLite
│   ├── exporter.php   ← SQLite → XMLTV / JSON / M3U
│   ├── layout.php     ← plantilla del panel
│   └── pages/         ← páginas (dashboard, fuentes, canales, guia, usuarios…)
├── cron/refresh.php   ← refresco automático (CLI)
├── data/              ← BD, sesiones, caché (NO commitear; protegida)
├── examples/sample-epg.xml  ← guía de ejemplo (61 canales, 3 días)
├── tools/generate_sample_epg.py ← regenerador de la guía de ejemplo
└── dev-server/        ← servidor de desarrollo PHP-WASM (Node, opcional)
```

## 🚀 Instalación (producción)

### cPanel / hosting compartido
1. Sube el contenido del proyecto. Lo ideal: `public/` como `public_html`
   y el resto al lado. *(Si no puedes, sube todo dentro de `public_html/` —
   el `.htaccess` de `data/` bloquea el acceso web en Apache.)*
2. Asegura permisos de escritura en `data/` (755 suele bastar; si no, 775).
3. Abre `https://tudominio/` → sale el **instalador**: nombre del servicio,
   zona horaria, usuario admin y contraseña. Crea la fuente inicial
   `http://38.226.161.148:5000/` (tu Astra) automáticamente.
4. Entra al panel → **Fuentes** → *Actualizar* para descargar tu guía.
   *(El servidor donde hospedes el panel debe poder alcanzar la IP del Astra.)*
5. **Usuarios y accesos** → crea un usuario, asígnale canales y cópiale su URL.

### CLI (VPS)
```bash
git clone https://github.com/TalaveraSama/epgweb.git /opt/epgweb
cd /opt/epgweb
php -S 0.0.0.0:8080 -t public    # prueba rápida
# Producción real: Apache/Nginx+PHP-FPM con docroot en /opt/epgweb/public
```

### Cron (refresco automático)
```bash
# cada 6 horas (ajusta la ruta):
0 0,6,12,18 * * *  php /opt/epgweb/cron/refresh.php all >> /var/log/epgweb.log 2>&1
```
También puedes lanzar refrescos desde el botón «Actualizar» del panel.

## 🔑 API de guía (para reproductores)

| URL | Qué devuelve |
|---|---|
| `api.php?k=CLAVE` | **XMLTV** (formato por defecto, para EPG de reproductores) |
| `api.php?k=CLAVE&format=json` | Guía en JSON (apps propias) |
| `api.php?k=CLAVE&format=m3u` | Lista M3U con `tvg-id` ya mapeado a la guía |
| `api.php?k=CLAVE&days=7` | Hasta 14 días de guía |
| `api.php?k=CLAVE&channels=ESPN2,HBO` | Solo esos canales (de los asignados) |
| `api.php?k=CLAVE&gzip=1` | Respuesta comprimida (ideal para XML grandes) |

También acepta la clave en cabecera `X-Api-Key`. Clave inválida → `401`.

**Ejemplo real** (con tu dominio):
```
https://tudominio/api.php?k=2b025ae410f8bad1b028986983d87cbf&format=xml&days=3
```

## 🛠️ Servidor de desarrollo sin PHP (Node + WebAssembly)

Para desarrollar o demostrar el panel **sin instalar PHP**, el repo incluye un
puente que ejecuta PHP 8.3 real compilado a WebAssembly (usa el FS real: la
base de datos y sesiones se guardan de verdad):

```bash
cd dev-server
npm install
node server.mjs          # http://localhost:8080
```

> Solo para desarrollo/demos. En producción usa PHP normal.

## 🧪 Datos de ejemplo

`examples/sample-epg.xml` contiene 61 canales (nombres reales de la guía del
cliente: Canal 2/4/6/8/10/12/13/14 NIC, ESPN, HBO, Cartoon Network, Discovery…)
con 3 días de programación en español. Impórtalo desde **Fuentes → 📂 Subir XML**
o regenera: `python3 tools/generate_sample_epg.py`.

## 🔒 Seguridad implementada

- Contraseñas con `password_hash()` (bcrypt) y verificación con `password_verify`.
- Sesiones: cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS, regeneración
  de ID al iniciar sesión, expiración por inactividad (8 h).
- Token **CSRF** en todos los formularios POST del panel.
- **PDO con consultas preparadas** en el 100 % del código.
- Límite de intentos de login (8 por usuario+IP en 15 min).
- Claves de API de 128 bits (`random_bytes`), usuario desactivable/vencible.
- `data/` con `.htaccess` de denegación total (Apache) — en Nginx añade la
  regla equivalente si `data/` queda dentro del docroot.
- Escapado de salida con `htmlspecialchars` en todas las vistas.

## 🗺️ Roadmap

- Deduplicación de un mismo canal entre varias fuentes.
- ETag/Last-Modified en la API para ahorrar tráfico.
- Códigos QR de los enlaces de acceso.
- Estadísticas por usuario con gráficas.
- Exportación XMLTV con `credits` (actores/directores) estructurados.

---

Hecho para [TalaveraSama/epgweb](https://github.com/TalaveraSama/epgweb).
Ver **ANOTACIONES.md** para las notas técnicas del desarrollo.
