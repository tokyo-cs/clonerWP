# clonador.php — WordPress to Clean Static HTML

Convert any WordPress site into a clean, dependency-free static HTML site with **a single PHP file**. No Node, no CLI tools, no plugins to install — you upload one file through your hosting's file manager, run it from the browser, and download a deploy-ready ZIP.

## What it does

When you run it, `clonador.php` works from *inside* your WordPress installation:

1. **Builds the full page list from the database** — every published page, post, and public custom post type (portfolio items, galleries, etc.), plus category archives. Because the list comes from the database instead of crawling links, no orphan or unlinked page is ever missed.
2. **Renders every page** by requesting it from WordPress itself, so you get exactly what visitors see.
3. **Cleans the markup** — removes the typical WordPress clutter: generator meta tag, emoji scripts, shortlinks, RSS/oEmbed/pingback links, HTML comments, `?ver=` query strings, and session-only body classes.
4. **Copies all assets directly from disk** (fast — no downloads): images keep their original `uploads/` folder structure under `assets/uploads/`, theme and plugin files go to `assets/wp/`. Fonts and backgrounds referenced *inside* CSS files are detected and copied too.
5. **Rewrites every URL to a relative path**, so the exported site works from anywhere: a domain root, a subfolder, a subdomain, or your local disk.
6. **Packages everything into `sitio-estatico.zip`**, ready to deploy on any server — no PHP or database required to host the result.
7. **Deletes itself from the server** when it finishes, so no admin tool is left behind.

## Why

- **Speed** — static HTML is served instantly; no PHP, no database queries.
- **Security** — there is nothing left to hack: no admin login, no plugins, no SQL.
- **Zero maintenance** — no more core/plugin/theme updates.
- **Freedom** — host the result anywhere: shared hosting, Netlify, GitHub Pages, an S3 bucket.

## Requirements

- A WordPress site you administer, with file manager (or FTP) access to its root folder.
- PHP 8.0+ on the hosting (any modern WordPress host qualifies).
- The `ZipArchive` PHP extension for the final ZIP (present on virtually all hosts; if missing, the script still generates the folder and tells you to download it directly).

Nothing is required on your own computer.

## Installation

**Step 1 — Set your secret token.**
Open `clonador.php` in any text editor and change this line near the top:

```php
const TOKEN_SECRETO = 'CAMBIAR-POR-UN-TOKEN-LARGO-Y-ALEATORIO';
```

Replace the placeholder with a long random string (20+ characters — any password generator works). The token is the script's password: without it, nobody can trigger the export.

> The script refuses to run if the placeholder value is left unchanged.

**Step 2 — Upload it.**
Using your hosting's file manager (cPanel, Plesk, etc.) or FTP, upload `clonador.php` to the **root folder of the WordPress installation** — the same directory that contains `wp-load.php` and `wp-config.php`.

## Usage

**Step 3 — Run it from the browser.**
Visit:

```
https://yoursite.com/clonador.php?token=YOUR_TOKEN
```

Depending on the size of the site this takes from seconds to a few minutes. When it finishes you'll see a plain-text summary: pages rendered, assets copied, ZIP size, and any errors.

**Step 4 — Preview it (optional).**
The summary prints a preview URL — the generated folder is browsable right away at:

```
https://yoursite.com/sitio-estatico/
```

Click around: since all paths are relative, the clone works from that subfolder exactly as it will work anywhere else.

**Step 5 — Download and deploy.**
From the file manager, download `sitio-estatico.zip`. Unzip it wherever the site will live — the root of a domain, a subdomain, any static host. The structure looks like this:

```
/
├── index.html
├── bio/index.html
├── contacto/index.html
├── gallery/…/index.html
├── assets/
│   ├── uploads/2024/…      ← media library, original structure
│   └── wp/                 ← theme & plugin CSS/JS
└── manifest… (summary of the run)
```

**Step 6 — Clean up the server.**
The script deletes itself automatically. You only need to delete the `sitio-estatico/` folder and the ZIP from the server once downloaded.

## Configuration

At the top of the file:

| Constant | Default | Purpose |
|---|---|---|
| `TOKEN_SECRETO` | — | Required secret to run the script |
| `CARPETA_SALIDA` | `sitio-estatico` | Output folder name |
| `INCLUIR_ARCHIVOS_CATEGORIA` | `true` | Also export category archive pages |
| `PAUSA_MS` | `100` | Pause between page renders (be gentle with slow hosts) |

## After migrating: things to know

- **Contact forms stop working.** Form plugins (Contact Form 7, etc.) need PHP. Point the form's `action` to an external service such as Formspree or Web3Forms — a one-line edit in the exported HTML.
- **Search stops working.** WordPress search needs the database. Either remove the search box or add a client-side search index later.
- **Comments, carts, and logins** — anything interactive server-side — won't work in a static site by design.
- **JavaScript-driven features** (galleries, sliders, lightboxes) are cloned along with their scripts and usually work as-is. Test them; anything calling `admin-ajax.php` will need attention.
- **Content updates** now mean editing HTML files or re-running the export against the WordPress source while it still exists.

## Security notes

- The token is compared with `hash_equals` (timing-safe). Still, upload the script and run it **immediately** — don't leave it sitting on the server.
- The script self-deletes after a successful run. If self-deletion fails (file permissions), the summary warns you: delete it manually.
- Before going live, review the export. If the source WordPress was ever compromised (spam posts, strange categories), the clone will faithfully include the junk — clean the source first, then re-run.

## Troubleshooting

- **403 "Acceso denegado"** — wrong or missing `?token=` parameter.
- **Blank page / timeout** — very large sites on slow hosts: raise `PAUSA_MS`, or ask your host about `max_execution_time` (the script already requests unlimited time, but some hosts enforce hard caps).
- **"asset no encontrado" errors** — the page references a file that doesn't exist on disk (often leftovers from removed plugins). Usually harmless; check the listed paths.
- **Styles missing in the preview** — hard-refresh (Ctrl/Cmd+Shift+R) to bypass the browser cache, and check the summary's error list for CSS files that could not be copied.

## License

MIT — use it, modify it, ship it.
