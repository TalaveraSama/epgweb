# 📦 INSTALACIÓN en tu hosting web — EPGWeb

Guía paso a paso para subir el panel a un hosting compartido (cPanel,
Plesk, DirectAdmin…) o a un VPS. **No necesitas Composer ni MySQL.**

---

## 📁 Qué archivos hay que copiar

### ✅ SÍ copiar (obligatorio)

| Carpeta/Archivo | Para qué es |
|---|---|
| `public/` **(todo su contenido)** | Es la parte web: `index.php` (panel), `api.php` (guía para reproductores), `assets/` (estilos) y `.htaccess` |
| `app/` **(todo)** | El código del panel (no es público) |
| `cron/refresh.php` | Refresco automático de la guía (opcional pero recomendado) |
| `data/` **(solo los 2 archivos que trae)** | `.htaccess` + `index.html` → protegen la carpeta donde se crearán la base de datos, sesiones y caché |

### 🔵 OPCIONAL

| Carpeta/Archivo | Cuándo |
|---|---|
| `examples/sample-epg.xml` | Guía de ejemplo (61 canales, 3 días) para probar la importación si tu Astra no está accesible desde el hosting |
| `README.md`, `ANOTACIONES.md`, `INSTALACION.md` | Documentación (no afecta el funcionamiento) |

### ❌ NO copiar

| Carpeta/Archivo | Motivo |
|---|---|
| `dev-server/` | Solo es un servidor de desarrollo con PHP-WASM (Node). No va al hosting |
| `tools/` | Generador de datos de ejemplo (Python) |
| `.git/`, `.gitignore` | Cosas del repositorio |
| `data/*.sqlite`, `data/sessions/`, `data/cache/`, `data/uploads/` | Se generan solos en tu hosting al usar el panel |

> 💡 **Atajo:** usa el archivo `epgweb-v1.0.0.zip` (generado junto a esta guía)
> que ya trae solo lo necesario listo para descomprimir.

---

## 🚀 Opción A — cPanel / hosting con carpeta `public_html` (la más común)

1. **Entra al Administrador de Archivos** de cPanel (o con FTP/FileZilla).
2. **Sube el ZIP** dentro de `public_html` y **extrae** su contenido.
   Al terminar debes tener esta estructura exacta:

```
public_html/
├── index.php          ← viene de public/
├── api.php            ← viene de public/
├── .htaccess          ← viene de public/  (activa "mostrar ocultos" para verlo)
├── assets/            ← viene de public/
│   ├── app.css
│   ├── app.js
│   └── favicon.svg
├── app/               ← todas las librerías PHP
│   ├── config.php
│   ├── db.php
│   ├── importer.php
│   ├── exporter.php
│   ├── …
│   └── pages/…
├── cron/
│   └── refresh.php
└── data/              ← SE CREA LA BASE DE DATOS AQUÍ
    ├── .htaccess      ← bloquea el acceso web (¡no lo borres!)
    └── index.html
```

   ⚠️ Lo importante: el **contenido de `public/` va suelto** en `public_html`
   (no dentro de una carpeta llamada `public/`), y las carpetas `app/`,
   `cron/` y `data/` al lado.

3. **Permisos** (normalmente ya vienen bien al extraer):
   - `data/` → `755` (si da error de escritura, prueba `775`)
   - archivos `.php` → `644`
4. Abre `https://tudominio.com/` → sale el **instalador**:
   - Nombre del servicio (ej. «EPG Talavera»)
   - Zona horaria: `America/Managua`
   - Usuario administrador + contraseña (mínimo 8)
   - Deja marcado «Crear la fuente EPG inicial» → ya queda registrada
     `http://38.226.161.148:5000/` (tu Astra)
5. Inicia sesión → **Fuentes → ↻ Actualizar** → descarga tu guía completa.
6. **Usuarios y accesos** → crea tu primer cliente, asígnale canales y cópiale
   su URL `https://tudominio.com/api.php?k=CLAVE&format=xml`.

## 🏠 Opción B — hosting que permite cambiar la raíz del dominio (más seguro)

1. Sube la carpeta completa `epgweb/` a tu home (ej. `/home/usuario/epgweb/`).
2. En la configuración del dominio/subdominio, cambia la **raíz del documento**
   (document root) a `/home/usuario/epgweb/public`.
3. Abre el dominio → instalador → igual que la opción A.

Con esto `app/`, `cron/` y `data/` quedan **fuera** de la web: imposible
descargarlos por URL aunque el `.htaccess` falle.

## 🖥️ Opción C — VPS con Apache/Nginx + PHP

```bash
# Debian/Ubuntu
apt install php php-sqlite3 php-curl php-mbstring php-xml unzip
cd /opt && unzip epgweb-v1.0.0.zip

# prueba rápida:
php -S 0.0.0.0:8080 -t /opt/epgweb/public
```

Para Apache, el VirtualHost con `DocumentRoot /opt/epgweb/public`.
Para **Nginx** (no lee `.htaccess`), protege `data` así:

```nginx
location ^~ /data/ { deny all; return 404; }
```

---

## ⏰ Cron (refresco automático de la guía)

En cPanel → «Trabajos de Cron» → añade (cada 6 horas, reemplaza la ruta):

```
0 0,6,12,18 * * *  php /home/TU_USUARIO/public_html/cron/refresh.php all >> /home/TU_USUARIO/epgweb-cron.log 2>&1
```

> En muchos cPanel el PHP CLI se llama así: `/usr/local/bin/php /ruta/cron/refresh.php all`
> (mira el botón «Command» del formulario de cron de tu cPanel).

## ✅ Checklist de requisitos del hosting

- PHP **7.4 o superior** (recomendado **8.1 / 8.2 / 8.3**)
  → cPanel: «Select PHP Version»
- Extensiones: `pdo_sqlite`, `sqlite3`, `xmlreader`/`dom`, `mbstring` o
  `iconv`, `json` (vienen activas en casi todos los hostings), `curl`
  (recomendado para descargar la guía del Astra)
- Carpeta `data/` con permiso de escritura
- Que el hosting pueda **salir a internet** y alcanzar `http://38.226.161.148:5000/`
  (si lo bloquea, usa la subida de archivo XML o pide al soporte que habran la IP)

## 🔧 Problemas frecuentes

| Síntoma | Causa probable / solución |
|---|---|
| «No se pudo abrir la base de datos» al instalar | `data/` sin permiso de escritura → `775` o `777` temporalmente |
| Página en blanco / error 500 | PHP < 7.4 o falta extensión → sube la versión en «Select PHP Version» |
| «Error HTTP al descargar (código 0)» al Actualizar | Tu hosting no alcanza la IP del Astra → prueba **Fuentes → 📂 Subir XML** con un archivo exportado, o revisa firewall |
| La importación se corta a la mitad | El hosting limita la duración de PHP (30–60 s) → usa el **cron** para refrescar |
| `https://tudominio.com/data/` muestra archivos | Tu servidor ignora `.htaccess` (Nginx) → aplica la regla `deny` de arriba |
| La guía sale con horas mal | Ajustes → zona horaria (`America/Managua` = UTC−6) |
| Quiero empezar de cero | Borra `data/epgweb.sqlite` y recarga → vuelve a salir el instalador |

## 🔄 Actualizar a una versión nueva

Sobrescribe `public/` y `app/` (y `cron/`). Tu base de datos, usuarios y
fuentes viven en `data/epgweb.sqlite` y no se tocan.
