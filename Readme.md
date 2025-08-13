# FS Snapshot Cache

Filesystem full-page cache for anonymous visitors (**WooCommerce-safe**) with gzip/brotli/HTML snapshots, precise invalidation, admin tools, disk-quota sweep — plus optional **LCP Boost** (with safe exclusions for Gravity Forms + reCAPTCHA).

> **Plugin slug:** `fs-snapshot-cache`
> **Version:** `0.10.0`
> **Author:** @hesum

---

## Why this plugin?

* **Zero database hits** for cached pages — serve pre-compressed **`.gz`** / **`.br`** files right from disk.
* **Safe by default** for WooCommerce carts/checkout/account, search, 404s, and form pages you specify.
* **Exact invalidation** on post/product/taxonomy/menu/theme/Customizer changes (plus a one-click admin-bar purge).
* **Daily quota sweep** to cap disk usage (oldest snapshots deleted first).
* **LCP Boost (optional)**: preload likely LCP image, inline critical CSS, defer non-critical JS with robust exclusions, preconnects, and font-preload.

> ⚡️ This plugin **creates** and **purges** snapshots. Your web server (Nginx/Apache) should be configured to **serve** those snapshots **before** WordPress/PHP for maximum speed. See **Server config** below.

---

## Features

* **Snapshot variants:** `gz`, `br`, `html` (choose any subset; default `gz`)
* **WooCommerce aware:** avoids caching cart/checkout/account, `?add-to-cart`, `wc-ajax`, and cart cookies
* **Do-not-cache paths:** textarea + constants + filter; defaults include `/free-estimates/` and `/contact/`
* **Precise purging** on:

  * post/page/product updates, status transitions, stock/price/meta changes
  * taxonomy term archives related to a post
  * home/product archives (paginated up to *N* pages)
  * global changes: menus, Customizer save, theme switch
* **Admin UX:**

  * Settings page at **Settings → FS Snapshot Cache**
  * Admin-bar button: **Purge this page (FS Cache)**
  * Maintenance buttons: **Run sweep now**, **Purge ALL**
* **WP-CLI:**

  * `wp fs-cache warm --what=all|home|posts|products|archives --limit=500`
  * `wp fs-cache purge --url=https://example.com/about/`
* **Quota control:** daily sweep to keep total bytes ≤ cap (0 = unlimited)
* **Debug headers:** `X-FS-*` for visibility during tuning
* **Filters & hooks** to override cacheability and JS-defer exclusions

---

## How it works (high-level)

1. For anonymous, cacheable GET/HEAD requests **without** query strings, carts, or excluded paths, the plugin buffers the final HTML.
2. It writes snapshots under `wp-content/cache/html-snapshots/{shard}/{md5(url)}.{variant}` atomically.
3. A subsequent request can be served **directly by the web server** (via `try_files` / rewrites) without invoking PHP.
4. When content changes, the plugin purges the exact affected URLs (including related archives and pagination).
5. A daily cron job enforces the disk quota by deleting oldest snapshots first.

---

## Requirements

* WordPress 5.8+ (PHP 7.4+) recommended
* Nginx **or** Apache with `.htaccess` support (for serving snapshots)
* Optional: `brotli` PHP extension if you enable `.br` variant

---

## Installation

1. Drop the plugin folder into `wp-content/plugins/fs-snapshot-cache/`.
2. Activate **FS Snapshot Cache** in **Plugins**.
3. (Recommended) Configure your **web server** to serve snapshots (see below).
4. Visit **Settings → FS Snapshot Cache** and adjust:

   * Variants (`gz`, `br`, `html`)
   * Archive pagination to cache (default: **3**)
   * Disk quota (default: **1 GiB**)
   * Base directory (default: `/cache/html-snapshots` under `wp-content`)
   * Never-cache paths (one per line)
   * Optional **LCP Boost** settings

---

## Server config (serve snapshots before PHP)

> The idea: for any request `/path/`, if a snapshot exists, serve it **directly** with the correct `Content-Encoding` and status, otherwise continue to PHP/WordPress.

### Nginx

```nginx
# Map Accept-Encoding to a snapshot suffix (prefer brotli, then gzip, else raw HTML)
map $http_accept_encoding $fs_suffix {
    "~*br"     ".br";
    "~*gzip"   ".gz";
    default    ".html";
}

# Root points at WordPress (e.g., /var/www/html); snapshots live under wp-content/cache/html-snapshots
location / {
    # Compute MD5 of normalized URL is done by plugin when writing; we use a try_files pattern:
    # Snapshots are sharded /wp-content/cache/html-snapshots/aa/bb/<md5>.<suffix>
    # We can't compute the md5 in nginx, so we use a small internal router:
    try_files /_snap$uri/ =404 @wordpress;
}

# Internal: try prebuilt files in all variants for the canonical, trailing-slash URL
location ^~ /_snap/ {
    internal;

    # normalize: ensure trailing slash
    rewrite ^/_snap(.+[^/])$ /_snap$1/ permanent;

    set $snap_dir  "/wp-content/cache/html-snapshots";
    # Because the on-disk path is sharded by md5, you cannot derive it here without lua/map.
    # Easiest approach: serve via PHP helper (very fast) that resolves md5 + shards.

    try_files /_fs-snapshot-proxy.php =404;
}

# Lightweight PHP helper to resolve md5 + shards and stream the right variant with headers.
location ~* ^/_fs-snapshot-proxy\.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/_fs-snapshot-proxy.php;
    fastcgi_pass php-fpm; # adjust
}
```

**PHP helper** (place at WordPress root as `_fs-snapshot-proxy.php`):

```php
<?php
// Minimal, read-only proxy to stream an existing snapshot if present.
// Requires no WP bootstrap; keep it tiny, fast.
$root = __DIR__;
$base = $root . '/wp-content/cache/html-snapshots';

$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$uri  = strtok($uri, '?');
if ($uri === '' || substr($uri, -1) !== '/') $uri .= '/';
$url  = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https':'http') . '://' . $_SERVER['HTTP_HOST'] . $uri, '/') . '/';

$key = md5($url);
$dir = $base . '/' . substr($key,0,2) . '/' . substr($key,2,2);
$variants = ['br'=>'br','gz'=>'gz','html'=>'html'];
$want = (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'br') !== false) ? 'br' :
        ((strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) ? 'gz' : 'html');

$candidates = array_unique([$want, 'gz', 'html', 'br']);
foreach ($candidates as $v) {
    $p = "$dir/$key.$v";
    if (is_file($p)) {
        if ($v === 'br') header('Content-Encoding: br');
        elseif ($v === 'gz') header('Content-Encoding: gzip');
        header('Cache-Control: public, max-age=300');
        header('Vary: Accept-Encoding');
        header('Content-Type: text/html; charset=UTF-8');
        readfile($p);
        exit;
    }
}
http_response_code(404);
```

> **Alternative (Apache)**: Similar logic is easier via a small PHP helper as above. If you prefer pure `.htaccess`, you’d need rewrite rules plus `mod_mime` to set `Content-Encoding`, but deriving the sharded md5 path is non-trivial without a helper. The helper approach is recommended.

---

## Admin settings (highlights)

* **Debug headers:** `X-FS-*` visibility
* **Variants:** choose any of `gz`, `br`, `html`
* **Archive pages to cache:** default **3** (adds `/page/2/`, `/page/3/`, … to warm/purge)
* **Disk quota (bytes):** default **1,073,741,824 (1 GiB)**; `0` = unlimited
* **Base directory:** default **`/cache/html-snapshots`** (under `wp-content`)
* **Never cache these paths:** textarea (substring match; one per line). Defaults include:

  * `/free-estimates/`
  * `/contact/`
* **Maintenance:** *Run sweep now*, *Purge ALL*

---

## LCP Boost (optional)

When enabled:

* **Critical CSS inline:** minimal above-the-fold CSS (`<style id="fs-critical-css">…`)
* **Preload likely LCP image:** featured image or main product image with `fetchpriority="high"`
* **Featured/product image attributes:** `loading="eager" fetchpriority="high" decoding="async"`
* **First content `<img>` on product pages:** forced eager/high
* **Preconnects:** uploads host, Google Fonts (optional)
* **Font preload:** one WOFF2 URL (optional) + a tiny `font-display: swap !important;`
* **Defer non-critical JS:** adds `defer` to most script tags while **never** deferring:

  * WP core/block packages (`wp-*`)
  * Safe defaults (toggled): jQuery, Gravity Forms, reCAPTCHA, Elementor, Popup Maker, Photoswipe, Woo single product, MediaElement, etc.
  * Additional handles & URL substrings you provide
* **Trims render blockers:** removes emojis + oEmbed discovery/host JS from `<head>`

---

## WP-CLI

Warm snapshots:

```bash
# Warm everything (home + posts/pages + products + archives), up to 500 URLs
wp fs-cache warm --what=all --limit=500

# Warm only products (WooCommerce)
wp fs-cache warm --what=products --limit=2000
```

Purge a specific URL:

```bash
wp fs-cache purge --url=https://example.com/about-us/
```

---

## Purging behavior

* **Per post/product update:** purges:

  * the singular URL
  * home page `/`
  * related post type archive (e.g., `/product/`)
  * related term archives (categories/tags/taxonomies)
  * paginated archives up to **N** pages (setting: *Archive pages to cache*)
* **Global changes:** menu updates, Customizer saves, theme switches → home + product archive
* **WooCommerce:** also purges on price/stock/status meta updates and scheduled sales

Admin-bar button: **Purge this page (FS Cache)** → purges the **current** URL.

---

## Debug headers

When **Debug headers** are enabled and not already sent:

* `X-FS-Cacheable: yes|no`
* `X-FS-Cache-Plugin: fs-snapshot-cache/0.10.0`
* `X-FS-Reason: <why not cacheable>` (e.g., `logged-in`, `query-string`, `wc-ajax`, `form-page`, …)
* `X-FS-Wrote: yes|no-<reason>`
* `X-FS-Error: mkdir-base-failed|mkdir-shard-failed` (if any)

---

## Configuration via constants (optional)

Define in `wp-config.php`:

```php
// Choose variants globally (subset of gz, br, html)
define('FS_SNAPSHOT_VARIANTS', ['br','gz']);

// Disk quota (bytes); 0 = unlimited
define('FS_SNAPSHOT_MAX_TOTAL_BYTES', 2 * 1024 * 1024 * 1024); // 2 GiB

// Cache archive pagination at least this many pages
define('FS_SNAPSHOT_ARCHIVE_PAGES', 5);

// Toggle debug headers
define('FS_SNAPSHOT_DEBUG', true);

// Additional never-cache paths
define('FS_SNAPSHOT_NO_CACHE_PATHS', ['/apply/', '/contact/thank-you/']);
```

---

## Filters & actions

```php
/**
 * Pre-cacheability veto/force.
 * Return bool to force allow/deny; or a string reason to explain a denial.
 */
add_filter('fs_snapshot_pre_cacheable', function($allow_or_reason, &$reason){
    // e.g., disable caching on specific user-agent
    if (!empty($_SERVER['HTTP_USER_AGENT']) && stripos($_SERVER['HTTP_USER_AGENT'],'Lighthouse') !== false) {
        return false;
    }
    return null; // let plugin decide
}, 10, 2);

/** Post-decision override: ($ok, $uri) → bool */
add_filter('fs_snapshot_cacheable', function($ok, $uri){
    return $ok;
}, 10, 2);

/** Defer-JS exclusions by handle (array of handles) */
add_filter('fs_snapshot_defer_exclude_handles', function($handles){
    $handles[] = 'my-critical-script';
    return array_unique($handles);
});

/** Defer-JS exclusions by URL substring (array of strings) */
add_filter('fs_snapshot_defer_exclude_substrings', function($subs){
    $subs[] = 'cdn.example.com/mission-critical.js';
    return array_unique($subs);
});

/** Never-cache paths (array of substrings) */
add_filter('fs_snapshot_no_cache_paths', function($paths){
    $paths[] = '/lead-form/';
    return array_unique($paths);
});

/** Fired after a URL is purged */
add_action('fs_snapshot_purged_url', function($url, $paths){
    // $paths contains the variant file paths that were removed
}, 10, 2);
```

---

## Cron / housekeeping

* Hook: `fs_snapshot_sweep_quota` (scheduled **daily** on activation; unscheduled on deactivation)
* Behavior: enumerates snapshot files, sums sizes, deletes **oldest** until under `max_total_bytes`

Manually trigger via **Run sweep now** or `wp cron event run fs_snapshot_sweep_quota`.

---

## Cacheability rules (summary)

**Cached when all true:**

* Not logged in
* Method is `GET` or `HEAD`
* **No** query string
* View is `singular`, `front page`, `home`, or `archive`
* **Not** search or 404
* **Not** Woo critical views: cart/checkout/account, `wc-ajax`, `?add-to-cart=…`, cart cookies present
* **Not** matched by **Never cache these paths**
* Filters don’t veto it

---

## Paths & storage

* Base: `<WP_CONTENT_DIR>/cache/html-snapshots` (configurable)
* Sharded by md5 of **normalized**, trailing-slash URL:

  ```
  /wp-content/cache/html-snapshots/aa/bb/<md5>.{gz|br|html}
  ```

---

## Warming tips

* Run after deploys or purges:

  ```bash
  wp fs-cache warm --what=all --limit=1000
  ```

* If your theme uses heavy archives, bump **Archive pages to cache** to cover `/page/2/…`.

---

## Troubleshooting

* **Seeing PHP hits for cached URLs?**

  * Ensure server is configured to **serve snapshots before PHP** (use helper)
  * Confirm snapshots exist under `wp-content/cache/html-snapshots/...`
  * Check request URL ends with `/` (the plugin normalizes to trailing slash)
* **Forms not submitting / CAPTCHA issues after enabling LCP Boost?**

  * Enable **Default safe exclusions**
  * Add any custom handles/substrings to **Never defer** lists
* **Disk usage keeps growing:**

  * Set **Disk quota** and confirm cron runs; or click **Run sweep now**
* **Headers missing:**

  * Enable **Debug headers** and ensure nothing has already sent output/headers

---

## Security & privacy

* Snapshots are static HTML of public pages for anonymous users only.
* Admin actions are nonce-protected and restricted to users with `manage_options`.
* The proxy helper suggested above only **reads** from the snapshot directory.

---

## Uninstall / cleanup

* Click **Purge ALL** on the settings page to remove snapshot files.
* Deactivating unschedules the quota sweep; snapshots remain on disk until purged.

---

## License

MIT (or your preferred license). Add a `LICENSE` file in the plugin root.

---

## Changelog

### 0.10.0

* Add **do-not-cache paths** (defaults: `/free-estimates/`, `/contact/`)
* Expand **LCP Boost** with safe JS defer exclusions and URL substring safeguards
* Admin polish and maintenance actions
* Daily quota sweep w/ oldest-first deletion
* WooCommerce meta/stock/sales-driven purges

---

## Credits

Built with ❤️ by @hesum. Contributions and issues welcome!
