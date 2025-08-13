# FS Snapshot Cache

Filesystem full-page cache for anonymous visitors (**WooCommerce-safe**) with gzip/brotli/HTML snapshots, precise invalidation, admin tools, disk-quota sweep — plus optional **LCP Boost** (with safe exclusions for Gravity Forms + reCAPTCHA).

Now includes **automatic installation of the early-serving `advanced-cache.php` drop-in** after activation — no manual file copying needed.

---

## Why FS Snapshot Cache?

* **Zero DB hits** for cached pages — serve pre-compressed `.gz` / `.br` snapshots right from disk.
* **WooCommerce-safe**: automatically avoids caching cart, checkout, account, and other dynamic pages.
* **Automatic early-serving drop-in**: `advanced-cache.php` is installed and enabled on activation, so snapshots can be served before WordPress loads.
* **Exact purge targeting** — only invalidates affected pages, archives, and pagination.
* **Disk quota control** with automatic sweeping of old snapshots.
* **Admin tools**: one-click purge buttons, sweep now, and settings.
* **LCP Boost** (optional) for faster Core Web Vitals.

---

## Key Features

* **Automatic `advanced-cache.php` setup** — no manual copying, no extra steps.
* **Snapshot variants**: choose `.gz`, `.br`, `.html` (default `.gz`).
* **Safe by default** for WooCommerce, login, search, 404s, and custom excluded paths.
* **Precise invalidation** on:

  * Post/page/product updates, status changes, stock or meta updates
  * Related taxonomy archives
  * Home and paginated archives
  * Menu, Customizer, and theme changes
* **Optional LCP Boost**: preload LCP image, inline critical CSS, defer non-critical JS (with safe exclusions).
* **Daily quota sweep** — keeps cache size under your configured limit.
* **WP-CLI commands**:

  * `wp fs-cache warm --what=all|home|posts|products|archives --limit=500`
  * `wp fs-cache purge --url=https://example.com/about/`
* **Debug headers** for tuning and diagnostics.

---

## How It Works

1. On activation, the plugin:

   * Creates the snapshot cache directory (default: `wp-content/cache/html-snapshots/`).
   * Installs `advanced-cache.php` into `wp-content/`.
   * Ensures `define('WP_CACHE', true);` exists in `wp-config.php`.
2. For anonymous, cacheable requests, the plugin stores a static HTML snapshot (and compressed variants if enabled).
3. The early-serving drop-in reads `.br` or `.gz` directly before WP loads, skipping dynamic pages.
4. On content change, related snapshots are purged.
5. Daily cron sweeps enforce the disk quota.

---

## Installation

### From WordPress Admin

1. Upload and activate **FS Snapshot Cache**.
2. The plugin automatically:

   * Installs and enables `advanced-cache.php`.
   * Ensures `WP_CACHE` is enabled in `wp-config.php`.
3. Go to **Settings → FS Snapshot Cache** to configure:

   * Variants (`gz`, `br`, `html`)
   * Archive pages to cache
   * Disk quota
   * Never-cache paths
   * LCP Boost (optional)

### From ZIP or Git

1. Upload folder to `wp-content/plugins/fs-snapshot-cache/`.
2. Activate plugin — setup runs automatically.
3. Adjust settings as above.

---

## Cacheability Rules

A request is cached only if:

* Method is GET or HEAD
* No query string
* Not logged in
* Not search results or 404
* Not WooCommerce cart/checkout/account
* Not excluded by path list
* View type is singular, home, or archive
* Filters allow it

---

## Early-Serving Drop-in (`advanced-cache.php`)

Automatically installed and activated.
It serves cached `.br` or `.gz` snapshots **before WP runs** if:

* Request is GET or HEAD
* No query string
* No cart/login cookies
* Path is not excluded

Sends:

* `304 Not Modified` when client cache is valid
* Correct `Content-Encoding` for `.br` / `.gz`

---

## Debug Headers

Enable debug mode in settings to see:

* `X-FS-Cacheable: yes|no`
* `X-FS-Reason: <why not cached>`
* `X-FS-Wrote: yes|no`
* `X-FS-Early: hit-br|hit-gz|miss|bypass`

---

## WP-CLI

```
wp fs-cache warm --what=all|home|posts|products|archives --limit=500
wp fs-cache purge --url=https://example.com/about/
```

---

## Purging Triggers

* Post/product/page update, delete, status change
* Related taxonomy updates
* Menu changes
* Customizer save
* Theme switch
* WooCommerce stock, price, sale schedule changes

---

## Changelog

### 0.11.0

* **NEW:** Automatic `advanced-cache.php` installation and WP\_CACHE enablement
* **Improved:** Safer file writes with atomic rename
* **Updated:** Readme for simpler setup

---

## License

GPLv2 or later

---

If you want, I can also **rewrite your plugin activation hook** so that it safely:

* Copies `advanced-cache.php`
* Updates `wp-config.php` only if needed
* Avoids duplicate constants or breaking custom setups

Would you like me to write that activation code next?
