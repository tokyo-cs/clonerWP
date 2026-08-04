<?php
/**
 * clonador.php — WordPress → sitio estático, en un solo archivo
 * -------------------------------------------------------------
 * Sin instalar nada: se sube por el administrador de archivos del
 * hosting y se ejecuta desde el navegador.
 *
 * Uso:
 *   1. Editar TOKEN_SECRETO (abajo) con una clave larga y aleatoria.
 *   2. Subir este archivo a la RAÍZ del WordPress (junto a wp-load.php).
 *   3. Visitar:  https://tusitio.com/clonador.php?token=TU_TOKEN
 *   4. Esperar el resumen. Descargar sitio-estatico.zip (o la carpeta
 *      sitio-estatico/) por el administrador de archivos.
 *   5. El script SE AUTOELIMINA al terminar. Borrar sitio-estatico/
 *      del servidor después de descargarlo.
 *
 * Qué hace:
 *   - Lista TODAS las páginas reales desde la base de datos (páginas,
 *     posts, custom post types públicos como galerías, y archivos de
 *     categorías con contenido). Nada se escapa por falta de links.
 *   - Renderiza cada una pidiéndosela al propio WordPress.
 *   - Limpia el HTML: generator, emojis, shortlinks, feeds, oembed,
 *     comentarios, versiones ?ver= — la basura típica de WP.
 *   - Copia imágenes y assets DIRECTO del disco (rápido, sin descargas)
 *     a assets/, preservando la estructura de uploads.
 *   - Reescribe todas las URLs a rutas relativas.
 *   - Empaqueta todo en sitio-estatico.zip.
 */

// ------------------------- CONFIG -------------------------
const TOKEN_SECRETO = 'CAMBIAR-POR-UN-TOKEN-LARGO-Y-ALEATORIO';
const CARPETA_SALIDA = 'sitio-estatico';   // se crea junto a este archivo
const INCLUIR_ARCHIVOS_CATEGORIA = true;   // páginas de categorías con posts
const PAUSA_MS = 100;                      // pausa entre renders
// ----------------------------------------------------------

// ==========================================================
// FUNCIONES PURAS (limpieza y reescritura, sin WordPress)
// ==========================================================

/** Elimina la basura típica de WP del HTML renderizado. */
function limpiar_html(string $html): string {
    $patrones = [
        // Metas y <link> basura del head:
        '/<meta[^>]+name=["\']generator["\'][^>]*>\s*/i',
        '/<link[^>]+rel=["\'](?:EditURI|wlwmanifest|shortlink|pingback|profile)["\'][^>]*>\s*/i',
        '/<link[^>]+rel=["\']https:\/\/api\.w\.org\/["\'][^>]*>\s*/i',
        '/<link[^>]+type=["\']application\/(?:json|xml)\+oembed["\'][^>]*>\s*/i',
        '/<link[^>]+type=["\']application\/rss\+xml["\'][^>]*>\s*/i',
        '/<link[^>]+rel=["\']dns-prefetch["\'][^>]+s\.w\.org[^>]*>\s*/i',
        // Script de emojis (bloque inline) y su estilo:
        '/<script[^>]*>[^<]*_wpemojiSettings[\s\S]*?<\/script>\s*/i',
        '/<script[^>]+wp-emoji[^>]*>\s*<\/script>\s*/i',
        '/<style[^>]*>\s*img\.wp-smiley[\s\S]*?<\/style>\s*/i',
        // Comentarios HTML (menos condicionales IE):
        '/<!--(?!\[if)[\s\S]*?-->/',
    ];
    $html = preg_replace($patrones, '', $html);
    // Clases de sesión del body (las de estilo se conservan):
    $html = preg_replace_callback('/(<body[^>]+class=["\'])([^"\']*)(["\'])/i', function ($m) {
        $clases = preg_split('/\s+/', $m[2]);
        $clases = array_filter($clases, fn($c) =>
            !preg_match('/^(logged-in|admin-bar|customize-support|no-customize-support)$/', $c)
        );
        return $m[1] . implode(' ', $clases) . $m[3];
    }, $html);
    // Colapsar líneas en blanco sobrantes:
    return preg_replace('/\n[ \t]*(\n[ \t]*)+/', "\n", $html);
}

/** Encuentra todas las rutas de assets de WP referenciadas en un HTML/CSS. */
function recolectar_assets(string $contenido, string $url_base): array {
    $rutas = [];
    // Con dominio o sin dominio, en atributos, srcset o url(...):
    $base = preg_quote($url_base, '/');
    if (preg_match_all(
        '/(?:' . $base . ')?(\/(?:wp-content|wp-includes)\/[^"\'\s\\\\)<>,]+)/i',
        $contenido, $m
    )) {
        foreach ($m[1] as $ruta) {
            $ruta = preg_replace('/[?#].*$/', '', $ruta); // sin ?ver= ni #
            if ($ruta && !str_ends_with($ruta, '.php')) $rutas[$ruta] = true;
        }
    }
    return array_keys($rutas);
}

/** Reescribe URLs del sitio a rutas relativas y assets a /assets/. */
function reescribir_urls(string $contenido, string $url_base): string {
    // Quitar ?ver= de los assets:
    $contenido = preg_replace('/(\/(?:wp-content|wp-includes)\/[^"\'\s\\\\)<>]+?)\?[^"\'\s\\\\)<>]*/', '$1', $contenido);
    // Uploads primero (más específico), después el resto:
    $contenido = str_replace(
        [$url_base . '/wp-content/uploads/', '/wp-content/uploads/'],
        '/assets/uploads/', $contenido
    );
    $contenido = str_replace(
        [$url_base . '/wp-includes/', '/wp-includes/'],
        '/assets/wp/includes/', $contenido
    );
    $contenido = str_replace(
        [$url_base . '/wp-content/', '/wp-content/'],
        '/assets/wp/', $contenido
    );
    // Links internos absolutos → relativos:
    $contenido = str_replace($url_base . '/', '/', $contenido);
    $contenido = str_replace($url_base, '/', $contenido);
    return $contenido;
}

/** Ruta local del archivo de salida para una URL de página. */
function ruta_de_pagina(string $url_relativa, string $dir_salida): string {
    $p = $url_relativa === '' || $url_relativa === '/' ? '/index.html'
       : rtrim($url_relativa, '/') . '/index.html';
    return $dir_salida . $p;
}

/** Mapea una ruta /wp-content/... a su destino en /assets/... */
function destino_de_asset(string $ruta): string {
    if (str_starts_with($ruta, '/wp-content/uploads/'))
        return '/assets/uploads/' . substr($ruta, strlen('/wp-content/uploads/'));
    if (str_starts_with($ruta, '/wp-includes/'))
        return '/assets/wp/includes/' . substr($ruta, strlen('/wp-includes/'));
    if (str_starts_with($ruta, '/wp-content/'))
        return '/assets/wp/' . substr($ruta, strlen('/wp-content/'));
    return '/assets/otros' . $ruta;
}

/** Prefijo ../ según la profundidad de la página ( / → '' ; /bio/ → ../ ). */
function prefijo_de_profundidad(string $url_relativa): string {
    $limpia = trim(preg_replace('/[?#].*$/', '', $url_relativa), '/');
    $niveles = $limpia === '' ? 0 : substr_count($limpia, '/') + 1;
    return str_repeat('../', $niveles);
}

/** Convierte las rutas raíz (/...) de un HTML en relativas con el prefijo dado. */
function relativizar_html(string $html, string $prefijo): string {
    // atributos comunes:
    $html = preg_replace(
        '/(\s(?:href|src|poster|action|data-src|data-bg|data-lazy-src|content))=(["\'])\/(?!\/)/i',
        '$1=$2' . $prefijo, $html
    );
    // srcset (varias URLs separadas por coma):
    $html = preg_replace_callback('/(\ssrcset=)(["\'])(.*?)\2/is', function ($m) use ($prefijo) {
        $v = preg_replace('/(^|,\s*)\/(?!\/)/', '$1' . $prefijo, $m[3]);
        return $m[1] . $m[2] . $v . $m[2];
    }, $html);
    // url(/...) en estilos inline y bloques <style>:
    return preg_replace('/url\(\s*([\'"]?)\/(?!\/)/i', 'url($1' . $prefijo, $html);
}

/** Ruta relativa desde un directorio hasta un archivo (para los CSS). */
function ruta_relativa_entre(string $desde_dir, string $hasta): string {
    $a = array_values(array_filter(explode('/', trim($desde_dir, '/')), 'strlen'));
    $b = array_values(array_filter(explode('/', trim($hasta, '/')), 'strlen'));
    while ($a && $b && $a[0] === $b[0]) { array_shift($a); array_shift($b); }
    return str_repeat('../', count($a)) . implode('/', $b);
}

// Permite testear las funciones puras sin WordPress:
if (defined('CLONADOR_TEST')) return;

// ==========================================================
// EJECUCIÓN (requiere WordPress)
// ==========================================================

if (!isset($_GET['token']) || !hash_equals(TOKEN_SECRETO, (string)$_GET['token'])) {
    http_response_code(403);
    exit('Acceso denegado.');
}
if (TOKEN_SECRETO === 'CAMBIAR-POR-UN-TOKEN-LARGO-Y-ALEATORIO') {
    exit('Configurá TOKEN_SECRETO antes de ejecutar.');
}

set_time_limit(0);
ini_set('memory_limit', '512M');
require_once __DIR__ . '/wp-load.php';

$URL_BASE  = rtrim(home_url(), '/');
$SALIDA    = __DIR__ . '/' . CARPETA_SALIDA;
$log       = [];
$errores   = [];
$assets_pendientes = [];   // ruta wp → true
$assets_copiados   = 0;

if (!is_dir($SALIDA)) mkdir($SALIDA, 0755, true);

// ---------- 1. Lista de URLs desde la base de datos ----------
$urls = ['/'];
foreach (get_post_types(['public' => true]) as $tipo) {
    if ($tipo === 'attachment') continue;
    foreach (get_posts([
        'post_type' => $tipo, 'post_status' => 'publish', 'posts_per_page' => -1,
    ]) as $p) {
        $link = get_permalink($p);
        if ($link) $urls[] = rtrim(str_replace($URL_BASE, '', $link), '/') . '/';
    }
}
if (INCLUIR_ARCHIVOS_CATEGORIA) {
    foreach (get_terms(['taxonomy' => 'category', 'hide_empty' => true]) as $t) {
        $link = get_term_link($t);
        if (!is_wp_error($link)) $urls[] = rtrim(str_replace($URL_BASE, '', $link), '/') . '/';
    }
}
$urls = array_values(array_unique($urls));
$log[] = 'URLs detectadas desde la base de datos: ' . count($urls);

// ---------- 2. Renderizar, limpiar, reescribir, guardar ----------
$paginas_ok = 0;
foreach ($urls as $u) {
    usleep(PAUSA_MS * 1000);
    $res = wp_remote_get($URL_BASE . $u, ['timeout' => 90, 'sslverify' => false]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        $errores[] = $u . ' — ' . (is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($res));
        continue;
    }
    $html = wp_remote_retrieve_body($res);
    $html = limpiar_html($html);
    foreach (recolectar_assets($html, $URL_BASE) as $ruta) $assets_pendientes[$ruta] = true;
    $html = reescribir_urls($html, $URL_BASE);
    $html = relativizar_html($html, prefijo_de_profundidad($u));
    $destino = ruta_de_pagina($u, $SALIDA);
    if (!is_dir(dirname($destino))) mkdir(dirname($destino), 0755, true);
    file_put_contents($destino, $html);
    $paginas_ok++;
}
$log[] = "Páginas renderizadas: $paginas_ok";

// ---------- 3. Copiar assets desde el disco (incluye CSS anidados) ----------
$procesados = [];
while ($assets_pendientes) {
    $ruta = array_key_first($assets_pendientes);
    unset($assets_pendientes[$ruta]);
    if (isset($procesados[$ruta])) continue;
    $procesados[$ruta] = true;

    $origen  = rtrim(ABSPATH, '/') . $ruta;
    $destino = $SALIDA . destino_de_asset($ruta);
    if (!file_exists($origen)) { $errores[] = "asset no encontrado: $ruta"; continue; }
    if (!is_dir(dirname($destino))) mkdir(dirname($destino), 0755, true);

    if (str_ends_with($ruta, '.css')) {
        // los CSS referencian más assets adentro (fuentes, fondos):
        $css = file_get_contents($origen);
        // resolver url(...) relativas a la ubicación del CSS:
        $dir_css = dirname($ruta);
        $css = preg_replace_callback('/url\(\s*[\'"]?(?!data:|https?:|\/\/|#)([^\'")]+)[\'"]?\s*\)/i',
            function ($m) use ($dir_css) {
                $abs = $dir_css . '/' . $m[1];
                // normalizar ../ :
                $partes = [];
                foreach (explode('/', $abs) as $seg) {
                    if ($seg === '..') array_pop($partes);
                    elseif ($seg !== '.' && $seg !== '') $partes[] = $seg;
                }
                return 'url(/' . implode('/', $partes) . ')';
            }, $css);
        foreach (recolectar_assets($css, '') as $r) {
            if (!isset($procesados[$r])) $assets_pendientes[$r] = true;
        }
        $css = reescribir_urls($css, '');
        // relativizar cada url(/assets/...) respecto a la ubicación del CSS:
        $dir_destino_css = dirname(destino_de_asset($ruta));
        $css = preg_replace_callback('/url\(\s*([\'"]?)(\/assets\/[^\'")]+)\1\s*\)/i',
            fn($m) => 'url(' . $m[1] . ruta_relativa_entre($dir_destino_css, $m[2]) . $m[1] . ')',
            $css);
        file_put_contents($destino, $css);
    } else {
        copy($origen, $destino);
    }
    $assets_copiados++;
}
$log[] = "Assets copiados: $assets_copiados";

// ---------- 4. ZIP ----------
$zip_ok = false;
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $zip_path = __DIR__ . '/sitio-estatico.zip';
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($SALIDA, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $archivo) {
            if ($archivo->getFilename() === '.htaccess') continue;
            $rel = substr($archivo->getPathname(), strlen($SALIDA) + 1);
            $zip->addFile($archivo->getPathname(), $rel);
        }
        $zip->close();
        $zip_ok = true;
        $log[] = 'ZIP creado: sitio-estatico.zip (' . round(filesize($zip_path) / 1048576, 1) . ' MB)';
    }
}
if (!$zip_ok) $log[] = 'No se pudo crear el ZIP (falta ZipArchive) — descargá la carpeta ' . CARPETA_SALIDA . '/ por el administrador de archivos.';

// ---------- 5. Resumen + autoeliminación ----------
header('Content-Type: text/plain; charset=utf-8');
echo "CLONADO COMPLETO\n================\n" . implode("\n", $log) . "\n";
if ($errores) {
    echo "\nErrores (" . count($errores) . "):\n- " . implode("\n- ", array_slice($errores, 0, 30)) . "\n";
    if (count($errores) > 30) echo "... y " . (count($errores) - 30) . " más\n";
}
echo "\nDescargá " . ($zip_ok ? "sitio-estatico.zip" : CARPETA_SALIDA . "/") . " por el administrador de archivos.\n";
echo "Previsualización: " . $URL_BASE . "/" . CARPETA_SALIDA . "/\n";
if (@unlink(__FILE__)) echo "Este script se autoeliminó del servidor. ✓\n";
else echo "ATENCIÓN: no se pudo autoeliminar — borrá clonador.php a mano.\n";
echo "Recordá borrar " . CARPETA_SALIDA . "/ y el ZIP del servidor después de descargarlos.\n";
