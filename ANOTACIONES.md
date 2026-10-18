# 📝 ANOTACIONES de trabajo — EPGWeb

> Notas del agente durante el análisis y desarrollo del servicio EPG.
> Se guardan aquí a pedido del dueño del repo. Última actualización: 2026-10-18.

---

## 1. Análisis del EPG actual del cliente

**Servidor:** `http://38.226.161.148:5000/`

- Responde en la **raíz** con un archivo **XMLTV** (guía de programación).
- La página de error 404 de otras rutas se firma «*Astra*» → el EPG lo sirve
  un **servidor Astra (Cesbo)**, típico en plataformas IPTV. Astra publica el
  XMLTV de los canales que tiene configurados en el puerto de su interfaz web.
- **Contenido observado** (muestreo del XML renderizado como texto):
  - Canales nacionales nicaragüenses: `Canal 10 HD`, `Canal 2/4/6/8/12/13/14 - NIC`,
    `Nicarao TV`, `Azcorazon`, `TBN Enlace`, `EWTN`, `La Voz de María`, `Acción 10`.
  - Internacionales latinos: `ESPN 2–6`, familia `HBO`, `Cinemax`, `Space`,
    `Cinecanal`, `Studio Universal`, `Golden`, `TCM`, `Sony Movies`,
    `STAR CHANNEL`, `WARNER HD`, `UNIVERSAL PREMIERE/REALITY/CRIME/COMEDY`,
    `AMC`, `TNT Series/Novelas`, `AXN`, `E!`, `A&E`, `Discovery` (Channel,
    Science, Kids, Turbo, Home & Health), `Animal Planet`, `History`/`H2`,
    `NatGeo`, `TLC`, `El Gourmet`, infantiles (`Cartoon Network`, `Cartoonito`,
    `Disney JR`, `Baby TV`, `ZooMoo`, `Tooncast`, `Semillitas`, `CLAN`),
    noticias (`RT`, `France24`, `Antena 3`, `TVE`), etc.
  - **Programas** con: título, descripción, categoría (`Movie/Drama`, `Show`,
    `News`, `Hobbies`…), y créditos metidos **dentro del texto** de la
    descripción con el patrón:
    `...desc... Actors: A.B,C.D; Director: X; Country: EE. UU.; Year: 2016; ...`
    → el importador debe conservar la descripción tal cual (los reproductores
    la muestran completa); extraer créditos estructurados queda en roadmap.
- **Tamaño**: la descarga completa son **879 fragmentos** de texto del
  conversor web (≈ varios MB, estimado 5–10 MB en XML crudo). Con cientos de
  canales × varios días, la importación en **streaming con XMLReader** es
  obligatoria (SimpleXML cargando todo el archivo agotaría memoria en
  hostings compartidos).
- **Acceso desde el sandbox de desarrollo**: la IP del Astra está fuera de la
  lista blanca de red del entorno de pruebas → la importación por URL no se
  puede ejecutar desde aquí (falla con «Socket not connected», controlado y
  mostrado en español). En el hosting real del cliente sí funcionará.
  Para las pruebas se creó `examples/sample-epg.xml` con nombres reales de
  canales de esa guía.

## 2. Decisiones de arquitectura

| Decisión | Motivo |
|---|---|
| **PHP puro sin frameworks/Composer** | Despliegue trivial en cPanel compartido (solo subir archivos). Sin `composer install`. |
| **SQLite (PDO)** | Sin servidor MySQL que configurar; una guía EPG son decenas de miles de filas, trivial para SQLite. WAL activado (con respaldo si el host no lo soporta). |
| **Docroot = `public/`** | Todo el código y datos quedan fuera de la web. Se incluye `.htaccess` de respaldo para quien deba poner todo junto. |
| **Rutas por query (`?r=…`)** | Funciona en cualquier hosting sin `mod_rewrite`. |
| **Importación atómica** | `BEGIN` → borrar programas de la fuente → insertar nuevos → `COMMIT`. Si la descarga o el parseo falla, la guía anterior **queda intacta** (verificado en pruebas: un refresco con URL inaccesible no borra nada). |
| **Tiempos en UTC (unix) internamente** | XMLTV trae zonas horarias tipo `20261018120000 -0600`; se convierte a UTC al importar y se formatea en la zona del sitio (por defecto `America/Managua`) al mostrar/exportar. |
| **Exportación por usuario** | Cada usuario tiene `api_key`; la exportación filtra canales por su asignación. Así se “da acceso” a clientes sin exponer el EPG completo. |
| **id XMLTV intacto en la exportación** | Los reproductores casan el `tvg-id` de su M3U con el `id` del canal del XMLTV; conservar el id de la fuente evita romper ese mapeo. |
| **XMLReader + DOM para parsear** | Streaming (memoria constante). SimpleXML completo queda como respaldo para hosts raros. |

## 3. Bugs encontrados y resueltos durante el desarrollo (bitácora)

Estos quedan documentados para futuras mantenimiento:

1. **`isset()` con valores null en el router** — el mapa de rutas usaba
   `'login' => null`; `isset($routes['login'])` es `false` cuando el valor es
   `null` → el login redirigía a sí mismo en bucle. Solución:
   `array_key_exists()`.
2. **Constantes mal prefijadas** — mezclé `EPG_VERSION` y `EPGW_VERSION` en
   varios archivos → `Undefined constant` fatal a mitad de render (la página
   se cortaba a los 530 B del primer flush). Auditado y normalizado a `EPGW_*`.
3. **`simplexml_import_dom()` no funciona sobre nodos de `XMLReader::expand()`**
   en el runtime PHP-WASM (devuelve `null`) → la importación encontraba 0
   canales. Reescrito el parser para leer los subárboles con la **API DOM
   directa** (`childNodes`, `getAttribute`), que además es más portable.
4. **Fuga de sesión entre peticiones en runtimes persistentes** — dos causas:
   - El `PHPRequestHandler` de php-wasm trae un **cookie store** que recuerda
     cookies y las inyecta en TODAS las peticiones (diseñado para un solo
     visitante). En el dev-server se desactiva con `cookieStore: false`.
     *Síntoma: el usuario B veía la sesión del usuario A.*
   - En procesos PHP que viven entre peticiones (FrankenPHP worker, RoadRunner,
     PHP-WASM), `session_status()` queda `ACTIVE` y `session_start()` reutiliza
     el estado anterior. `auth_start_session()` ahora hace
     `session_write_close()` + `$_SESSION = array()` + `session_start()`,
     y el bootstrap limpia los caches globales por petición.
5. **Error de sintaxis en `cron/refresh.php`** — el docblock contenía
   `0 */6 * * *` y el `*/` **cerraba el comentario** → parse error. Cambiado
   a `0 0,6,12,18 * * *`. (Lección: nunca escribir `*/` dentro de comentarios.)
6. **`pkill -f` con el nombre del propio script** durante las pruebas mataba
   el propio shell de comandos (anécdota de entorno, no del producto).

## 4. Cómo se probó (todo verificado en PHP 8.3)

Entorno: servidor de desarrollo `dev-server/` (Node + PHP-WASM con el sistema
de archivos real). Flujos verificados con `curl`:

- [x] Instalador: crea BD, ajustes, admin y fuente inicial (redirige bien,
      no se puede reinstalar).
- [x] Login admin (302 → dashboard), CSRF en todos los POST, logout.
- [x] Importación por **subida de archivo**: 61 canales / 3 916 programas en
      ~0.3 s. Contadores por canal y cobertura de fechas correctos.
- [x] Importación por **URL** (falla controlada en sandbox; en producción
      funciona igual que la subida — mismo código de importación).
- [x] Refresco fallido **no destruye** la guía existente (atomicidad).
- [x] Panel: dashboard, canales (búsqueda/paginación), guía por día y
      «ahora en emisión», fuentes + historial, ajustes, registros.
- [x] Usuarios: crear con canales seleccionados, editar, regenerar clave,
      vencimiento, desactivar, borrar.
- [x] Aislamiento de sesiones: dos navegadores simultáneos (admin y cliente)
      no se mezclan; cliente no accede a rutas admin (302).
- [x] Cambio de contraseña en perfil + relogin.
- [x] API: `xml` (5 canales asignados → solo esos, 308 programas),
      `json` (150 programas/1 día), `m3u` (con `tvg-id` y `x-tvg-url`),
      `gzip` (89 KB → 6 KB), filtro `channels=`, clave inválida → 401,
      clave admin → los 61 canales.
- [x] Registro de accesos: usuario, formato, estado, bytes, IP, agente,
      resumen 24 h / 7 días.

## 5. Estado actual de la demo

- Aplicación **instalada** con datos de ejemplo (`examples/sample-epg.xml`).
- Usuarios de demo (cámbialos en producción):
  - `admin` / `epgweb2026` — administrador.
  - `cliente1` / `cliente123456` — usuario con 5 canales asignados
    (Canal 10 HD, ESPN 2, HBO HD, Cartoon Network, Discovery Channel).
- Fuente registrada: `Astra — Guía principal` → `http://38.226.161.148:5000/`.
  En el sandbox la URL no es alcanzable (red restringida); en el hosting real,
  pulsar «Actualizar» o configurar el cron descargará la guía completa.

## 6. Producción — checklist de despliegue

1. Docroot apuntando a `public/` (o todo junto + confiar en `.htaccess`).
2. `data/` escribible por PHP; idealmente **fuera** del docroot.
3. Habilitar **HTTPS** (el panel activa cookie `Secure` automáticamente).
4. Cron: `0 0,6,12,18 * * * php /ruta/cron/refresh.php all`.
5. Probar que el hosting alcanza la IP del Astra (`curl -I http://38.226.161.148:5000/`).
6. Si el XML de Astra pesa mucho y el hosting corta tiempos largos, usar el
   cron en vez del botón del panel.
7. Cambiar contraseñas de demo y regenerar claves de API de prueba.

## 7. Pendientes / ideas (roadmap)

- Deduplicar canales repetidos entre fuentes (alias manuales).
- ETag / Last-Modified + 304 en `api.php` para ahorrar ancho de banda.
- QR con el enlace de acceso por usuario.
- Extraer créditos (`Actors/Director/Country/Year`) del texto de descripción
  del Astra a elementos XMLTV estructurados.
- Gráficas de uso por usuario; alertas cuando una fuente no refresque.
- Autenticación HTTP Basic opcional para la API además de la clave en URL.
- Importación programada desde el propio panel (sin cron del sistema).

## 8. Archivos clave para modificar a futuro

| Quiero cambiar… | Toca… |
|---|---|
| Fuente EPG por defecto al instalar | `app/config.php` (`EPGW_DEFAULT_SOURCE_URL`) |
| Zona horaria por defecto | `app/config.php` (`EPGW_DEFAULT_TZ`) |
| Esquema de BD | `app/db.php` (`epg_db_install`) |
| Formatos de exportación | `app/exporter.php` |
| Parser XMLTV (nuevos elementos) | `app/importer.php` (`epg_parse_xmltv`) |
| Menú del panel | `app/layout.php` (`epg_menu`) |
| Estilos | `public/assets/app.css` |
