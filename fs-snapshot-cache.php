<?php
/**
 * Plugin Name: FS Snapshot Cache
 * Description: Filesystem full-page cache for anonymous visitors (WooCommerce-safe) with gzip/brotli/html snapshots, precise invalidation, admin tools, disk quota sweep — plus optional LCP Boost (with safe exclusions for Gravity Forms + reCAPTCHA). Automatically installs an early-serving drop-in (advanced-cache.php).
 * Version: 0.11.0
 * Author: hoosh.pro
 */

if (!defined('ABSPATH')) exit;

final class FS_Snapshot_Cache {
    // -------------------- Defaults (overridable via Settings or defines) --------------------
    const VERSION                = '0.11.0';

    const DEFAULT_BASE_DIR        = '/cache/html-snapshots';   // under wp-content
    const DEFAULT_VARIANTS        = ['gz'];                    // choose from: gz, br, html
    const DEFAULT_DEBUG_HEADERS   = true;                      // X-FS-* headers
    const DEFAULT_ARCHIVE_PAGES   = 3;                         // archive pagination to cache/purge
    const DEFAULT_MAX_TOTAL_BYTES = 1073741824;                // 1 GiB quota

    // Safety defaults
    const DEFAULT_NO_CACHE_PATHS  = ['/free-estimates/', '/contact/'];

    // Handles we never defer (safe for GF/Elementor/Popup Maker/jQuery)
    const DEFAULT_DEFER_EXCLUDE_HANDLES = [
        'jquery','jquery-core','jquery-migrate',
        'gform_gravityforms','gravityforms','gforms_conditional_logic',
        'google-recaptcha','recaptcha',
        'elementor-frontend','elementor-pro-frontend',
        'popup-maker-site','pum-site-scripts',
        'wp-polyfill','wp-emoji-release','wp-embed','wp-util','underscore','backbone',
        // Woo/players that often break when deferred:
        'photoswipe','photoswipe-ui-default','wc-single-product','wc-add-to-cart-variation',
        'mediaelement','mejs-core','mejs-migrate',
    ];

    // Substrings in SRC we never defer (extra belt & suspenders)
    const DEFAULT_DEFER_EXCLUDE_URL_SUBSTR = [
        'recaptcha/api.js','google.com/recaptcha','gstatic.com/recaptcha',
        'gravityforms','grecaptcha',
    ];

    // Automations
    const SWEEP_HOOK = 'fs_snapshot_sweep_quota';

    // Drop-in install markers/paths
    const DROPIN_BASENAME = 'advanced-cache.php';
    const DROPIN_HEADER   = "/* FS Snapshot Early Cache (managed by fs-snapshot-cache) */";
    const OPTION_DROPIN_STATE = 'fs_snapshot_dropin_state'; // { installed: bool, ours: bool, reason?: string }

    public function __construct() {
        // Capture just before template output
        add_action('template_redirect', [$this, 'maybe_start_buffer'], 0);

        // Invalidation
        add_action('save_post',                 [$this, 'purge_post_related'], 10, 3);
        add_action('before_delete_post',        [$this, 'purge_post_related'], 10, 1);
        add_action('transition_post_status',    [$this, 'on_status_change'], 10, 3);

        if (class_exists('WooCommerce')) {
            add_action('woocommerce_update_product',             [$this, 'purge_product'], 10, 1);
            add_action('woocommerce_update_stock',               [$this, 'purge_product'], 10, 1);
            add_action('woocommerce_product_set_stock',          [$this, 'purge_product_from_obj'], 10, 1);
            add_action('woocommerce_variation_set_stock',        [$this, 'purge_product_from_obj'], 10, 1);
            add_action('woocommerce_product_set_stock_status',   [$this, 'purge_product'], 10, 1);
            add_action('woocommerce_variation_set_stock_status', [$this, 'purge_product'], 10, 1);
            add_action('updated_post_meta',                      [$this, 'maybe_purge_on_meta'], 10, 4);
            add_action('woocommerce_scheduled_sales',            [$this, 'purge_globals'], 10);
        }

        // Global/site changes
        add_action('wp_update_nav_menu',   [$this, 'purge_globals'], 10);
        add_action('customize_save_after', [$this, 'purge_globals'], 10);
        add_action('switch_theme',         [$this, 'purge_globals'], 10);

        // Admin bar “Purge this page”
        add_action('admin_bar_menu', [$this, 'adminbar_purge_node'], 999);
        add_action('wp_ajax_fs_snapshot_purge_current', [$this, 'ajax_purge_current']);

        // Settings
        add_action('admin_menu',  [$this, 'add_settings_page']);
        add_action('admin_init',  [$this, 'register_settings']);
        add_action('admin_post_fs_snapshot_sweep',     [$this, 'handle_sweep_now']);
        add_action('admin_post_fs_snapshot_purge_all', [$this, 'handle_purge_all']);

        // Notices for drop-in / WP_CACHE
        add_action('admin_notices', [$this, 'maybe_admin_notices']);

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('fs-cache', [$this, 'cli']);
        }

        // Cron
        add_action(self::SWEEP_HOOK, [$this, 'sweep_quota']);

        // Activate/deactivate hooks
        register_activation_hook(__FILE__,  [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);

        // ---- LCP Boost (conditional) ----
        if ($this->opt('lcp_enable')) {
            add_action('wp_head', [$this, 'lcp_inline_critical_css'], 1);
            add_action('wp_head', [$this, 'lcp_preload_image'], 2);
            add_filter('wp_get_attachment_image_attributes', [$this, 'lcp_featured_img_attrs'], 10, 3);
            add_filter('the_content', [$this, 'lcp_first_img_eager_on_product'], 20);
            if ($this->opt('lcp_preconnect_uploads')) add_action('wp_head', [$this, 'lcp_preconnect_uploads'], 1);
            if ($this->opt('lcp_preconnect_gfonts'))  add_action('wp_head', [$this, 'lcp_preconnect_google_fonts'], 1);
            if ($this->opt('lcp_font_preload_url'))   add_action('wp_head', [$this, 'lcp_preload_font'], 3);
            add_action('wp_enqueue_scripts', [$this, 'lcp_font_display_swap'], 100);
            if ($this->opt('lcp_defer_js'))           add_filter('script_loader_tag', [$this, 'lcp_defer_scripts'], 10, 3);
            add_action('init', [$this, 'lcp_trim_render_blockers']);
        }
    }

    // -------------------- Activation / Deactivation --------------------

    public static function activate() {
        // Schedule daily sweep
        if (!wp_next_scheduled(self::SWEEP_HOOK)) {
            wp_schedule_event(time() + 300, 'daily', self::SWEEP_HOOK);
        }

        // Ensure WP_CACHE is true (best effort)
        self::ensure_wp_cache_true();

        // Install advanced-cache.php drop-in (best effort)
        self::install_dropin();
    }

    public static function deactivate() {
        $ts = wp_next_scheduled(self::SWEEP_HOOK);
        if ($ts) wp_unschedule_event($ts, self::SWEEP_HOOK);

        // Remove our drop-in if we installed it (don’t touch if not ours)
        self::maybe_remove_our_dropin();
    }

    private static function install_dropin() {
        $dropin_path = WP_CONTENT_DIR . '/' . self::DROPIN_BASENAME;

        // If a file exists and is NOT ours, do not overwrite.
        if (is_file($dropin_path) && !self::is_our_dropin($dropin_path)) {
            update_option(self::OPTION_DROPIN_STATE, ['installed'=>true, 'ours'=>false, 'reason'=>'existing-foreign-dropin'], false);
            return;
        }

        // Write our drop-in
        $bytes = self::dropin_source();
        $ok = @file_put_contents($dropin_path, $bytes);
        if ($ok === false) {
            update_option(self::OPTION_DROPIN_STATE, ['installed'=>false, 'ours'=>false, 'reason'=>'write-failed'], false);
            return;
        }

        // Success
        update_option(self::OPTION_DROPIN_STATE, ['installed'=>true, 'ours'=>true], false);
    }

    private static function maybe_remove_our_dropin() {
        $dropin_path = WP_CONTENT_DIR . '/' . self::DROPIN_BASENAME;
        if (is_file($dropin_path) && self::is_our_dropin($dropin_path)) {
            @unlink($dropin_path);
        }
        delete_option(self::OPTION_DROPIN_STATE);
    }

    private static function is_our_dropin($path): bool {
        $fh = @fopen($path, 'r');
        if (!$fh) return false;
        $head = fread($fh, 256);
        fclose($fh);
        return (strpos((string)$head, self::DROPIN_HEADER) !== false);
    }

    private static function ensure_wp_cache_true() {
        if (defined('WP_CACHE') && WP_CACHE) return;

        $wp_config = self::locate_wp_config_path();
        if (!$wp_config || !is_writable($wp_config)) {
            // We’ll show an admin notice
            return;
        }

        $contents = @file_get_contents($wp_config);
        if ($contents === false) return;

        if (strpos($contents, 'define(\'WP_CACHE\'') !== false || strpos($contents, 'define("WP_CACHE"') !== false) {
            // Try to flip to true if set false
            $contents = preg_replace('/define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*false\s*\)\s*;/', 'define(\'WP_CACHE\', true);', $contents, 1);
        } else {
            // Insert just after opening <?php
            $contents = preg_replace('/<\?php\s*/', "<?php\ndefine('WP_CACHE', true);\n", $contents, 1);
        }

        @file_put_contents($wp_config, $contents);
    }

    private static function locate_wp_config_path() {
        // Typical path
        $path = ABSPATH . 'wp-config.php';
        if (is_file($path)) return $path;

        // One directory up (some setups)
        $path = dirname(ABSPATH) . '/wp-config.php';
        if (is_file($path)) return $path;

        return null;
    }

    private static function dropin_source(): string {
        // This is the exact early-serving logic you provided, wrapped with our header marker.
        return <<<'PHP'
<?php
/* FS Snapshot Early Cache (managed by fs-snapshot-cache) */
/**
 * Safe early-serving of static snapshots before WordPress loads.
 * Serves .br or .gz files if present and valid; falls through to WP otherwise.
 * Avoids serving 0-byte or truncated files.
 *
 * Optional overrides in wp-config.php:
 *   define('FS_SNAPSHOT_BASE_DIR', '/cache/html-snapshots'); // under wp-content (default)
 *   define('FS_SNAPSHOT_VARIANTS', ['gz','br']);             // any of ['gz','br','html'] (html is NOT served here)
 */

if (defined('WP_INSTALLING')) return;

// ---- Tunables (safe defaults) ----
if (!defined('FS_SNAPSHOT_MIN_BYTES')) define('FS_SNAPSHOT_MIN_BYTES', 1024); // min valid filesize for a snapshot

// Resolve base snapshots directory (under wp-content)
function fsac_base_dir() {
    $rel = defined('FS_SNAPSHOT_BASE_DIR') ? FS_SNAPSHOT_BASE_DIR : '/cache/html-snapshots';
    if ($rel === '' || $rel[0] !== '/') $rel = '/'.$rel; // ensure leading slash
    return rtrim(WP_CONTENT_DIR, '/') . $rel;
}

// Should we attempt early serve for this request?
function fsac_allowed(): bool {
    // Only GET/HEAD
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m !== 'GET' && $m !== 'HEAD') return false;

    // Not logged in
    foreach (array_keys($_COOKIE ?? []) as $c) {
        if (stripos($c, 'wordpress_logged_in_') === 0) return false;
    }

    // Woo critical paths
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (preg_match('~/(cart|checkout|my-account|order-pay|order-received)(/|$)~i', $uri)) return false;

    // No query string
    if (!empty($_GET)) return false;

    // No active cart cookies
    $cookies = array_change_key_case($_COOKIE ?? [], CASE_LOWER);
    if (!empty($cookies['woocommerce_items_in_cart']) || !empty($cookies['woocommerce_cart_hash'])) return false;

    return true;
}

// Normalize request path and build stable cache key
function fsac_norm_path($p) {
    $p = parse_url($p ?: '/', PHP_URL_PATH) ?? '/';
    $p = preg_replace('~//+~', '/', $p);
    return substr($p, -1) === '/' ? $p : $p . '/';
}
function fsac_key(): string {
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path   = fsac_norm_path($_SERVER['REQUEST_URI'] ?? '/');
    return md5($scheme . '://' . $host . $path);
}

// Map key to shard paths
function fsac_paths($key) {
    $a = substr($key, 0, 2);
    $b = substr($key, 2, 2);
    $dir = fsac_base_dir() . '/' . $a . '/' . $b;
    return [
        'br'   => $dir . '/' . $key . '.br',
        'gz'   => $dir . '/' . $key . '.gz',
        'html' => $dir . '/' . $key . '.html',
        'dir'  => $dir,
    ];
}

// Is this snapshot file OK?
function fsac_ok_file($path): bool {
    return is_file($path) && filesize($path) > FS_SNAPSHOT_MIN_BYTES;
}

// Emit common cache headers
function fsac_send_common_headers() {
    header('Vary: Accept-Encoding, Cookie', true);
    header('Cache-Control: public, max-age=300, stale-while-revalidate=30, stale-if-error=86400', true);
    header('Content-Type: text/html; charset=UTF-8', true);
}

// Optionally handle conditional requests (304)
function fsac_maybe_conditional_304($file) {
    $mtime = @filemtime($file);
    if (!$mtime) return false;
    $lastMod = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    header('Last-Modified: ' . $lastMod, true);

    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if ($since && $since >= $mtime) {
            header('X-FS-Early-Result: not-modified');
            http_response_code(304);
            return true;
        }
    }
    return false;
}

// ------------ Main flow ------------
$allowed = fsac_allowed();
if (!headers_sent()) {
    header('X-FS-Early: ' . ($allowed ? 'allowed' : 'bypass'));
}
if (!$allowed) return;

$key = fsac_key();
$paths = fsac_paths($key);
$ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

if (!headers_sent()) {
    header('X-FS-Cache-Key: ' . substr($key, 0, 12));
    header('X-FS-Dir: ' . $paths['dir']);
}

// Prefer Brotli, then gzip. (We intentionally do NOT serve raw HTML here.)
if (stripos($ae, 'br') !== false && fsac_ok_file($paths['br'])) {
    if (fsac_maybe_conditional_304($paths['br'])) exit;
    fsac_send_common_headers();
    header('Content-Encoding: br', true);
    header('X-Snapshot: FILE-BROTLI');
    header('X-FS-Early-Result: hit-br');
    readfile($paths['br']); exit;
}
if (stripos($ae, 'gzip') !== false && fsac_ok_file($paths['gz'])) {
    if (fsac_maybe_conditional_304($paths['gz'])) exit;
    fsac_send_common_headers();
    header('Content-Encoding: gzip', true);
    header('X-Snapshot: FILE-GZIP');
    header('X-FS-Early-Result: hit-gz');
    readfile($paths['gz']); exit;
}

// Miss or bad files: cleanup any 0-byte artifacts and fall through
foreach (['br','gz','html'] as $v) {
    $p = $paths[$v] ?? null;
    if ($p && is_file($p) && filesize($p) === 0) @unlink($p);
}

if (!headers_sent()) header('X-FS-Early-Result: miss');
// Fall through so WordPress can render; the plugin will write a fresh snapshot.
PHP;
    }

    // -------------------- Capture & Store (unchanged core) --------------------

    public function maybe_start_buffer() {
        $reason = '';
        $ok = $this->is_cacheable($reason);

        if ($this->debug() && !headers_sent()) {
            header('X-FS-Cacheable: ' . ($ok ? 'yes' : 'no'));
            header('X-FS-Cache-Plugin: fs-snapshot-cache/'.self::VERSION);
            if (!$ok) header('X-FS-Reason: ' . substr($reason, 0, 120));
        }
        if (!$ok) return;

        $base = $this->baseDir();
        if (!is_dir($base) && !wp_mkdir_p($base)) {
            if ($this->debug() && !headers_sent()) header('X-FS-Error: mkdir-base-failed');
            return;
        }
        ob_start([$this, 'capture_and_store']);
    }

    public function capture_and_store(string $html): string {
        $reason = '';
        if (!$this->is_cacheable($reason) || is_feed()) {
            if ($this->debug() && !headers_sent()) header('X-FS-Wrote: no-'.$reason);
            return $html;
        }

        // Remove admin bar defensively
        $html = preg_replace('#<div id="wpadminbar".*?</div>#s', '', $html);

        $url = $this->current_url_normalized();
        $key = md5($url);
        [$dir, $paths] = $this->sharded_paths($key);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            if ($this->debug() && !headers_sent()) header('X-FS-Error: mkdir-shard-failed');
            return $html;
        }

        $ok_any = false;
        if (isset($paths['html'])) $ok_any = $this->atomic_write($paths['html'], $html) || $ok_any;
        if (isset($paths['gz']) && function_exists('gzencode')) {
            $gz = gzencode($html, 6);
            if ($gz !== false) $ok_any = $this->atomic_write($paths['gz'], $gz) || $ok_any;
        }
        if (isset($paths['br']) && function_exists('brotli_compress')) {
            if (!defined('BROTLI_TEXT')) define('BROTLI_TEXT', 1);
            $br = brotli_compress($html, 5, BROTLI_TEXT);
            if ($br !== false) $ok_any = $this->atomic_write($paths['br'], $br) || $ok_any;
        }

        if ($this->debug() && !headers_sent()) header('X-FS-Wrote: ' . ($ok_any ? 'yes' : 'no'));
        return $html;
    }

    private function atomic_write(string $path, string $bytes): bool {
        $tmp = $path . '.tmp' . uniqid('', true);
        $ok  = (@file_put_contents($tmp, $bytes, LOCK_EX) !== false);
        if ($ok) $ok = @rename($tmp, $path);
        if (!$ok) @unlink($tmp);
        return $ok;
    }

    // -------------------- Cacheability --------------------

    private function is_cacheable(?string &$reason = null): bool {
        $pre = apply_filters('fs_snapshot_pre_cacheable', null, $reason);
        if ($pre !== null) { $reason = is_string($pre) ? $pre : 'filtered'; return (bool)$pre; }

        if (is_user_logged_in()) { $reason = 'logged-in'; return false; }
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($m !== 'GET' && $m !== 'HEAD') { $reason = 'method'; return false; }
        if (!empty($_GET)) { $reason = 'query-string'; return false; }
        if (is_search() || is_404()) { $reason = 'search/404'; return false; }
        if (function_exists('is_cart') && (is_cart() || is_checkout() || (function_exists('is_account_page') && is_account_page()))) {
            $reason = 'wc-critical'; return false;
        }
        $cookies = array_change_key_case($_COOKIE ?? [], CASE_LOWER);
        if (!empty($cookies['woocommerce_items_in_cart'])) { $reason = 'cart-items'; return false; }
        if (!empty($cookies['woocommerce_cart_hash']))    { $reason = 'cart-hash';  return false; }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (stripos($uri, 'add-to-cart=') !== false) { $reason = 'add-to-cart'; return false; }
        if (stripos($uri, '/wc-ajax/') !== false)    { $reason = 'wc-ajax';     return false; }

        $no_cache_paths = $this->no_cache_paths();
        foreach ($no_cache_paths as $path) {
            if ($path !== '' && stripos($uri, $path) !== false) {
                $reason = 'form-page';
                return false;
            }
        }

        $ok = (is_singular() || is_front_page() || is_home() || is_archive());
        $ok = (bool)apply_filters('fs_snapshot_cacheable', $ok, $uri);
        $reason = $ok ? 'ok' : 'view-not-whitelisted';
        return $ok;
    }

    private function current_url_normalized(): string {
        global $wp;
        $path = $wp->request ?? '';
        return trailingslashit(home_url($path));
    }

    // -------------------- Invalidation (unchanged) --------------------

    public function on_status_change($new, $old, $post) {
        if (isset($post->ID)) $this->purge_post_related((int)$post->ID);
    }
    public function purge_product($product_id) { $this->purge_post_related((int)$product_id); }
    public function purge_product_from_obj($product) {
        if (is_object($product) && method_exists($product, 'get_id')) $this->purge_post_related((int)$product->get_id());
    }
    public function maybe_purge_on_meta($meta_id, $post_id, $meta_key, $meta_value) {
        static $keys = ['_price','_regular_price','_sale_price','_stock','_stock_status','_tax_class'];
        if (in_array($meta_key, $keys, true)) $this->purge_post_related((int)$post_id);
    }

    public function purge_globals() {
        $this->purge_url(trailingslashit(home_url('/')));
        if (post_type_exists('product')) {
            $pta = get_post_type_archive_link('product');
            if ($pta) $this->purge_url(trailingslashit($pta));
        }
    }

    public function purge_post_related(int $post_id) {
        foreach ($this->urls_for_post($post_id) as $url) $this->purge_url($url);
    }

    private function purge_url(string $url) {
        $key = md5($url);
        [$dir, $paths] = $this->sharded_paths($key);
        foreach ($paths as $f) if ($f && is_file($f)) @unlink($f);
        do_action('fs_snapshot_purged_url', $url, $paths);
    }

    private function urls_for_post(int $post_id): array {
        $urls = [];
        $p = get_permalink($post_id); if ($p) $urls[] = trailingslashit($p);
        $urls[] = trailingslashit(home_url('/'));

        $pt = get_post_type($post_id);
        if ($pt) {
            $pta = get_post_type_archive_link($pt); if ($pta) $urls[] = trailingslashit($pta);
            $taxes = get_object_taxonomies($pt, 'names');
            foreach ($taxes as $tax) {
                $terms = wp_get_post_terms($post_id, $tax);
                foreach ($terms as $t) { $link = get_term_link($t); if (!is_wp_error($link)) $urls[] = trailingslashit($link); }
            }
        }

        $N = $this->archivePages();
        if ($N > 1) {
            $extra = [];
            foreach ($urls as $u) for ($i=2; $i<= $N; $i++) $extra[] = $u."page/$i/";
            $urls = array_merge($urls, $extra);
        }
        return array_values(array_unique(array_filter($urls)));
    }

    // -------------------- Paths & Config --------------------

    private function baseDir(): string {
        $rel = $this->opt('base_dir', self::DEFAULT_BASE_DIR);
        if ($rel === '' || $rel[0] !== '/') $rel = '/'.$rel;
        return rtrim(WP_CONTENT_DIR, '/') . $rel;
    }

    private function sharded_paths(string $key): array {
        $a = substr($key, 0, 2);
        $b = substr($key, 2, 2);
        $dir = $this->baseDir() . "/$a/$b";
        $variants = $this->variants();
        $paths = [
            'html' => in_array('html',$variants,true) ? "$dir/$key.html" : null,
            'gz'   => in_array('gz',$variants,true)   ? "$dir/$key.gz"   : null,
            'br'   => in_array('br',$variants,true)   ? "$dir/$key.br"   : null,
        ];
        return [$dir, array_filter($paths)];
    }

    private function variants(): array {
        $v = $this->opt('variants');
        if (is_array($v) && $v) return array_values(array_intersect($v, ['gz','br','html']));
        if (defined('FS_SNAPSHOT_VARIANTS') && is_array(FS_SNAPSHOT_VARIANTS) && FS_SNAPSHOT_VARIANTS) return FS_SNAPSHOT_VARIANTS;
        return self::DEFAULT_VARIANTS;
    }

    private function archivePages(): int {
        $n = (int)$this->opt('archive_pages', self::DEFAULT_ARCHIVE_PAGES);
        if (defined('FS_SNAPSHOT_ARCHIVE_PAGES')) $n = max($n, (int)FS_SNAPSHOT_ARCHIVE_PAGES);
        return max(1, $n);
    }

    private function maxBytes(): int {
        $v = (int)$this->opt('max_total_bytes', self::DEFAULT_MAX_TOTAL_BYTES);
        if (defined('FS_SNAPSHOT_MAX_TOTAL_BYTES')) $v = (int)FS_SNAPSHOT_MAX_TOTAL_BYTES;
        return max(0, $v);
    }

    private function debug(): bool {
        $v = $this->opt('debug', self::DEFAULT_DEBUG_HEADERS);
        if (defined('FS_SNAPSHOT_DEBUG')) $v = (bool)FS_SNAPSHOT_DEBUG;
        return (bool)$v;
    }

    private function opts(): array {
        $o = get_option('fs_snapshot_options', []);
        return is_array($o) ? $o : [];
    }
    private function opt(string $key, $default=null) {
        $o = $this->opts();
        return array_key_exists($key,$o) ? $o[$key] : $default;
    }

    // -------------------- Quota Sweep --------------------

    public function sweep_quota() {
        $max = $this->maxBytes();
        if ($max <= 0) return;
        $base = $this->baseDir();
        if (!is_dir($base)) return;

        [$total, $files] = $this->list_files_and_size($base);
        if ($total <= $max) return;

        usort($files, function($a,$b){ return $a['mtime'] <=> $b['mtime']; }); // oldest first
        foreach ($files as $f) {
            if (is_file($f['path'])) @unlink($f['path']);
            $total -= $f['size'];
            if ($total <= $max) break;
        }
    }

    private function list_files_and_size(string $base): array {
        $total = 0;
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $size = $file->getSize();
            $total += $size;
            $files[] = ['path'=>$file->getPathname(),'size'=>$size,'mtime'=>$file->getMTime()];
        }
        return [$total, $files];
    }

    // -------------------- Admin Bar --------------------

    public function adminbar_purge_node($wp_admin_bar) {
        if (!current_user_can('manage_options') || is_admin()) return;
        $url = home_url(add_query_arg([]));
        $href = wp_nonce_url(admin_url('admin-ajax.php?action=fs_snapshot_purge_current&url='.rawurlencode($url)), 'fs_snapshot_purge');
        $wp_admin_bar->add_node([
            'id'    => 'fs-snapshot-purge',
            'title' => 'Purge this page (FS Cache)',
            'href'  => $href,
        ]);
    }
    public function ajax_purge_current() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('fs_snapshot_purge');
        $url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
        if (!$url) wp_die('no url');
        $this->purge_url(trailingslashit($url));
        wp_safe_redirect($url); exit;
    }

    // -------------------- Settings (unchanged UI) --------------------

    public function add_settings_page() {
        add_options_page('FS Snapshot Cache', 'FS Snapshot Cache', 'manage_options', 'fs-snapshot-cache', [$this, 'render_settings_page']);
    }
    public function register_settings() {
        register_setting('fs_snapshot_group', 'fs_snapshot_options', [
            'type' => 'array',
            'sanitize_callback' => function($in){
                $out = [];
                // Cache
                $out['debug'] = !empty($in['debug']);
                $out['variants'] = isset($in['variants']) && is_array($in['variants'])
                    ? array_values(array_intersect($in['variants'], ['gz','br','html'])) : ['gz'];
                $out['archive_pages']   = max(1, (int)($in['archive_pages'] ?? 3));
                $out['max_total_bytes'] = max(0, (int)($in['max_total_bytes'] ?? 1073741824));
                $bd = trim($in['base_dir'] ?? self::DEFAULT_BASE_DIR);
                $out['base_dir'] = $bd === '' ? self::DEFAULT_BASE_DIR : (strpos($bd,'/')===0 ? $bd : '/'.$bd);

                // No-cache paths
                $nc = isset($in['no_cache_paths']) ? (string)$in['no_cache_paths'] : '';
                $lines = array_filter(array_map('trim', preg_split('#\R+#', $nc)));
                $out['no_cache_paths'] = $lines ?: self::DEFAULT_NO_CACHE_PATHS;

                // LCP Boost
                $out['lcp_enable']               = !empty($in['lcp_enable']);
                $out['lcp_defer_js']             = !empty($in['lcp_defer_js']);
                $out['lcp_preconnect_uploads']   = !empty($in['lcp_preconnect_uploads']);
                $out['lcp_preconnect_gfonts']    = !empty($in['lcp_preconnect_gfonts']);
                $out['lcp_font_preload_url']     = esc_url_raw($in['lcp_font_preload_url'] ?? '');
                $out['lcp_critical_css']         = (string)($in['lcp_critical_css'] ?? '');
                $out['lcp_default_exclusions']   = !empty($in['lcp_default_exclusions']);

                // Handles (comma/space separated)
                $handles = isset($in['defer_exclude_handles']) ? (string)$in['defer_exclude_handles'] : '';
                $handles = preg_split('#[\s,]+#', $handles, -1, PREG_SPLIT_NO_EMPTY);
                $out['defer_exclude_handles'] = $handles ?: [];

                // URL substrings (one per line)
                $subs = isset($in['defer_exclude_substrings']) ? (string)$in['defer_exclude_substrings'] : '';
                $subs = array_filter(array_map('trim', preg_split('#\R+#', $subs)));
                $out['defer_exclude_substrings'] = $subs ?: [];

                return $out;
            }
        ]);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $o = $this->opts();
        $variants = $o['variants'] ?? ['gz'];
        $base = esc_attr($o['base_dir'] ?? self::DEFAULT_BASE_DIR);

        // Pre-fill textareas/fields
        $no_cache_paths = implode("\n", $this->no_cache_paths(false));
        $exclude_handles = implode(', ', $o['defer_exclude_handles'] ?? []);
        $exclude_subs    = implode("\n", $o['defer_exclude_substrings'] ?? []);
        ?>
        <div class="wrap">
          <h1>FS Snapshot Cache</h1>

          <?php $this->render_dropin_status_box(); ?>

          <form method="post" action="options.php">
            <?php settings_fields('fs_snapshot_group'); ?>

            <h2 class="title">Caching</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">Debug headers</th>
                <td><label><input type="checkbox" name="fs_snapshot_options[debug]" value="1" <?php checked(!empty($o['debug'])); ?>> Send X-FS-* headers</label></td>
              </tr>
              <tr>
                <th scope="row">Variants</th>
                <td>
                  <label><input type="checkbox" name="fs_snapshot_options[variants][]" value="gz" <?php checked(in_array('gz',$variants,true)); ?>> gzip (.gz)</label><br>
                  <label><input type="checkbox" name="fs_snapshot_options[variants][]" value="br" <?php checked(in_array('br',$variants,true)); ?>> brotli (.br)</label><br>
                  <label><input type="checkbox" name="fs_snapshot_options[variants][]" value="html" <?php checked(in_array('html',$variants,true)); ?>> raw HTML (.html)</label>
                </td>
              </tr>
              <tr>
                <th scope="row">Archive pages to cache</th>
                <td><input type="number" min="1" name="fs_snapshot_options[archive_pages]" value="<?php echo esc_attr($o['archive_pages'] ?? 3); ?>"></td>
              </tr>
              <tr>
                <th scope="row">Disk quota (bytes)</th>
                <td>
                  <input type="number" min="0" name="fs_snapshot_options[max_total_bytes]" value="<?php echo esc_attr($o['max_total_bytes'] ?? 1073741824); ?>">
                  <p class="description">0 = unlimited. Daily sweep deletes oldest files until under the cap.</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Base directory</th>
                <td>
                  <code><?php echo esc_html(rtrim(WP_CONTENT_DIR,'/')); ?></code>
                  <input type="text" name="fs_snapshot_options[base_dir]" value="<?php echo $base; ?>" style="width:320px">
                </td>
              </tr>
              <tr>
                <th scope="row">Never cache these paths</th>
                <td>
                  <textarea name="fs_snapshot_options[no_cache_paths]" rows="4" style="width:100%;font-family:monospace;"><?php echo esc_textarea($no_cache_paths); ?></textarea>
                  <p class="description">One path per line (substring match). Defaults include <code>/free-estimates/</code> and <code>/contact/</code>.</p>
                </td>
              </tr>
            </table>

            <h2 class="title">LCP Boost (optional)</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">Enable LCP Boost</th>
                <td><label><input type="checkbox" name="fs_snapshot_options[lcp_enable]" value="1" <?php checked(!empty($o['lcp_enable'])); ?>> Turn on image/text LCP optimizations</label></td>
              </tr>
              <tr>
                <th scope="row">Defer most JS</th>
                <td><label><input type="checkbox" name="fs_snapshot_options[lcp_defer_js]" value="1" <?php checked(!empty($o['lcp_defer_js'])); ?>> Add <code>defer</code> to non-critical scripts (with safe exclusions)</label></td>
              </tr>
              <tr>
                <th scope="row">Default safe exclusions</th>
                <td><label><input type="checkbox" name="fs_snapshot_options[lcp_default_exclusions]" value="1" <?php checked(!empty($o['lcp_default_exclusions'])); ?>> Always exclude Gravity Forms, reCAPTCHA, Elementor, Popup Maker, jQuery</label></td>
              </tr>
              <tr>
                <th scope="row">Never defer these <em>handles</em></th>
                <td>
                  <input type="text" name="fs_snapshot_options[defer_exclude_handles]" value="<?php echo esc_attr($exclude_handles); ?>" style="width:100%;">
                  <p class="description">Comma/space separated WP script handles.</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Never defer when SRC contains</th>
                <td>
                  <textarea name="fs_snapshot_options[defer_exclude_substrings]" rows="4" style="width:100%;font-family:monospace;"><?php echo esc_textarea($exclude_subs); ?></textarea>
                  <p class="description">One substring per line (e.g. <code>recaptcha/api.js</code>, <code>gravityforms</code>).</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Preconnect uploads host</th>
                <td><label><input type="checkbox" name="fs_snapshot_options[lcp_preconnect_uploads]" value="1" <?php checked(!empty($o['lcp_preconnect_uploads'])); ?>> Speed up first image connection</label></td>
              </tr>
              <tr>
                <th scope="row">Preconnect Google Fonts</th>
                <td><label><input type="checkbox" name="fs_snapshot_options[lcp_preconnect_gfonts]" value="1" <?php checked(!empty($o['lcp_preconnect_gfonts'])); ?>> Add preconnects to <code>fonts.gstatic.com</code> / <code>fonts.googleapis.com</code></label></td>
              </tr>
              <tr>
                <th scope="row">Preload a WOFF2 font (URL)</th>
                <td><input type="url" name="fs_snapshot_options[lcp_font_preload_url]" placeholder="https://cdn.example.com/fonts/Heading-700.woff2" value="<?php echo esc_attr($o['lcp_font_preload_url'] ?? ''); ?>"></td>
              </tr>
              <tr>
                <th scope="row">Critical CSS (inline)</th>
                <td>
                  <textarea name="fs_snapshot_options[lcp_critical_css]" rows="8" style="width:100%;font-family:monospace;"><?php echo esc_textarea($o['lcp_critical_css'] ?? ''); ?></textarea>
                  <p class="description">Paste only the minimal above-the-fold CSS (header/nav/H1). 3–8 KB is plenty.</p>
                </td>
              </tr>
            </table>

            <?php submit_button('Save changes'); ?>
          </form>

          <hr>
          <h2>Maintenance</h2>
          <p>
            <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=fs_snapshot_sweep'), 'fs_snapshot_sweep' ) ); ?>">Run sweep now</a>
            <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=fs_snapshot_purge_all'), 'fs_snapshot_purge_all' ) ); ?>" onclick="return confirm('Purge ALL snapshots?');">Purge ALL</a>
          </p>
        </div>
        <?php
    }

    private function render_dropin_status_box() {
        $state = get_option(self::OPTION_DROPIN_STATE, []);
        $dropin_path = WP_CONTENT_DIR . '/' . self::DROPIN_BASENAME;
        $have = is_file($dropin_path);
        $ours = $have && self::is_our_dropin($dropin_path);
        $wp_cache_on = defined('WP_CACHE') && WP_CACHE;

        echo '<div class="notice notice-info" style="padding:12px;margin-top:10px;">';
        echo '<strong>Early-serving drop-in:</strong> ';
        if ($have) {
            echo $ours ? 'installed (managed by plugin).' : 'present (managed by another plugin/system).';
        } else {
            echo 'not installed.';
        }
        echo '<br><strong>WP_CACHE:</strong> ' . ($wp_cache_on ? 'enabled' : 'disabled');
        if (!$wp_cache_on) {
            echo '<br><em>Add this to wp-config.php:</em> <code>define(\'WP_CACHE\', true);</code>';
        }
        echo '</div>';
    }

    public function handle_sweep_now() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('fs_snapshot_sweep');
        $this->sweep_quota();
        wp_safe_redirect( admin_url('options-general.php?page=fs-snapshot-cache&fsmsg=swept') ); exit;
    }
    public function handle_purge_all() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('fs_snapshot_purge_all');
        $base = $this->baseDir();
        $this->rrmdir($base);
        wp_mkdir_p($base);
        wp_safe_redirect( admin_url('options-general.php?page=fs-snapshot-cache&fsmsg=purged') ); exit;
    }
    private function rrmdir($dir) {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }

    // -------------------- WP-CLI --------------------

    /**
     * Usage:
     *   wp fs-cache warm --what=products --limit=2000
     *   wp fs-cache purge --url=https://site.tld/about-us/
     */
    public function cli($args, $assoc) {
        $sub = $args[0] ?? 'warm';
        if ($sub === 'purge' && !empty($assoc['url'])) {
            $this->purge_url(untrailingslashit($assoc['url']).'/');
            \WP_CLI::success('Purged: '.$assoc['url']);
            return;
        }

        $what  = $assoc['what']  ?? 'all'; // all|home|posts|products|archives
        $limit = (int)($assoc['limit'] ?? 500);
        $urls  = [];

        if ($what === 'all' || $what === 'home') {
            $urls[] = trailingslashit(home_url('/'));
        }

        if ($what === 'all' || $what === 'posts') {
            $q = new \WP_Query([
                'post_type'      => ['post','page'],
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => $limit,
                'no_found_rows'  => true
            ]);
            foreach ($q->posts as $id) $urls[] = trailingslashit(get_permalink($id));
        }

        if (class_exists('WooCommerce') && ($what === 'all' || $what === 'products')) {
            $q = new \WP_Query([
                'post_type'      => ['product'],
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => $limit,
                'no_found_rows'  => true
            ]);
            foreach ($q->posts as $id) $urls[] = trailingslashit(get_permalink($id));
        }

        if ($what === 'all' || $what === 'archives') {
            $pta = post_type_exists('product') ? get_post_type_archive_link('product') : null;
            if ($pta) $urls[] = trailingslashit($pta);
        }

        $urls = array_values(array_unique(array_filter($urls)));
        foreach ($urls as $u) {
            \WP_CLI::line("Render $u");
            $resp = wp_remote_get($u, ['timeout'=>10, 'headers'=>['Cache-Prime'=>'1']]);
            if (is_wp_error($resp)) \WP_CLI::warning($resp->get_error_message());
        }
        \WP_CLI::success('Warmup done ('.count($urls).' URLs).');
    }

    // -------------------- LCP Boost (same as before) --------------------

    public function lcp_inline_critical_css() {
        $css = trim((string)$this->opt('lcp_critical_css',''));
        if ($css === '') return;
        echo "\n<style id='fs-critical-css'>\n{$css}\n</style>\n";
    }

    public function lcp_preload_image() {
        if (is_admin()) return;
        $img_url = ''; $srcset=''; $sizes='';
        if (function_exists('is_product') && is_product()) {
            global $product;
            if ($product && method_exists($product,'get_image_id')) {
                $id = $product->get_image_id();
                if ($id) {
                    $src = wp_get_attachment_image_src($id, 'full');
                    $img_url = $src ? $src[0] : '';
                    $srcset  = wp_get_attachment_image_srcset($id, 'full') ?: '';
                    $sizes   = wp_get_attachment_image_sizes($id, 'full') ?: '100vw';
                }
            }
        }
        if (!$img_url && is_singular() && has_post_thumbnail()) {
            $id = get_post_thumbnail_id();
            $src = wp_get_attachment_image_src($id, 'full');
            $img_url = $src ? $src[0] : '';
            $srcset  = wp_get_attachment_image_srcset($id, 'full') ?: '';
            $sizes   = wp_get_attachment_image_sizes($id, 'full') ?: '100vw';
        }
        if ($img_url) {
            echo "\n<link rel='preload' as='image' href='".esc_url($img_url)."' ".($srcset?"imagesrcset='".esc_attr($srcset)."' ":"").($sizes?"imagesizes='".esc_attr($sizes)."' ":"")."fetchpriority='high'>\n";
        }
    }

    public function lcp_featured_img_attrs($attr, $attachment, $size) {
        if (is_singular() && get_post_thumbnail_id() === $attachment->ID) {
            $attr['loading'] = 'eager';
            $attr['fetchpriority'] = 'high';
            $attr['decoding'] = 'async';
            if (empty($attr['sizes'])) $attr['sizes'] = '(max-width: 768px) 100vw, 70vw';
        }
        if (function_exists('is_product') && is_product()) {
            global $product;
            if ($product && method_exists($product,'get_image_id') && $product->get_image_id() == $attachment->ID) {
                $attr['loading'] = 'eager';
                $attr['fetchpriority'] = 'high';
                $attr['decoding'] = 'async';
                if (empty($attr['sizes'])) $attr['sizes'] = '(max-width: 768px) 100vw, 70vw';
            }
        }
        return $attr;
    }

    public function lcp_first_img_eager_on_product($html) {
        if (!function_exists('is_product') || !is_product()) return $html;
        return preg_replace_callback('#<img[^>]*>#i', function($m){
            static $i=0; $i++;
            $tag = $m[0];
            if ($i>1) return $tag;
            $tag = preg_replace('#\sloading=("|\')?lazy\1#i','',$tag);
            if (stripos($tag,' loading=')===false) $tag = preg_replace('#<img#i','<img loading="eager"',$tag,1);
            if (stripos($tag,' fetchpriority=')===false) $tag = preg_replace('#<img#i','<img fetchpriority="high"',$tag,1);
            if (stripos($tag,' decoding=')===false) $tag = preg_replace('#<img#i','<img decoding="async"',$tag,1);
            return $tag;
        }, $html, 1);
    }

    public function lcp_preconnect_uploads() {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['baseurl'])) {
            $scheme = parse_url($uploads['baseurl'], PHP_URL_SCHEME);
            $host   = parse_url($uploads['baseurl'], PHP_URL_HOST);
            if ($scheme && $host) echo "\n<link rel='preconnect' href='".esc_url("$scheme://$host")."' crossorigin>\n";
        }
    }

    public function lcp_preconnect_google_fonts() {
        echo "\n<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>\n";
        echo "<link rel='preconnect' href='https://fonts.googleapis.com'>\n";
    }

    public function lcp_preload_font() {
        $url = $this->opt('lcp_font_preload_url', '');
        if ($url) echo "\n<link rel='preload' href='".esc_url($url)."' as='font' type='font/woff2' crossorigin>\n";
    }

    public function lcp_font_display_swap() {
        wp_register_style('fs-lcp-dummy', false);
        wp_enqueue_style('fs-lcp-dummy');
        wp_add_inline_style('fs-lcp-dummy', '@font-face{font-display:swap !important;}');
    }

    public function lcp_defer_scripts($tag, $handle, $src){
        if (is_admin()) return $tag;

        // 1) Never defer WP core/block packages
        if (strpos($handle, 'wp-') === 0) return $tag;

        // 2) Build exclusion lists (defaults + user-provided)
        $ex_handles = [];
        if (!empty($this->opt('lcp_default_exclusions'))) {
            $ex_handles = array_merge($ex_handles, self::DEFAULT_DEFER_EXCLUDE_HANDLES);
        }
        $ex_handles = array_merge($ex_handles, (array)$this->opt('defer_exclude_handles', []));
        $ex_handles = apply_filters('fs_snapshot_defer_exclude_handles', array_values(array_unique($ex_handles)));
        if (in_array($handle, $ex_handles, true)) return $tag;

        $ex_subs = [];
        if (!empty($this->opt('lcp_default_exclusions'))) {
            $ex_subs = array_merge($ex_subs, self::DEFAULT_DEFER_EXCLUDE_URL_SUBSTR);
        }
        $ex_subs = array_merge($ex_subs, (array)$this->opt('defer_exclude_substrings', []));
        $ex_subs = apply_filters('fs_snapshot_defer_exclude_substrings', array_values(array_unique($ex_subs)));

        $lower = strtolower((string)$src);
        foreach ($ex_subs as $needle) {
            if ($needle !== '' && strpos($lower, strtolower($needle)) !== false) return $tag;
        }

        // Already module or deferred?
        if (strpos($tag, ' type="module"') !== false || strpos($tag, ' defer ') !== false) return $tag;

        // Add defer
        $tag = str_replace(' src', ' defer src', $tag);
        return $tag;
    }

    public function lcp_trim_render_blockers() {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
    }

    // -------------------- Helpers --------------------

    private function no_cache_paths(bool $include_defaults = true): array {
        $user = (array)$this->opt('no_cache_paths', []);
        $const = defined('FS_SNAPSHOT_NO_CACHE_PATHS') && is_array(FS_SNAPSHOT_NO_CACHE_PATHS) ? FS_SNAPSHOT_NO_CACHE_PATHS : [];
        $defaults = $include_defaults ? self::DEFAULT_NO_CACHE_PATHS : [];
        $all = array_values(array_unique(array_filter(array_map('trim', array_merge($defaults, $const, $user)))));
        return apply_filters('fs_snapshot_no_cache_paths', $all);
    }

    public function maybe_admin_notices() {
        if (!current_user_can('manage_options')) return;

        $state = get_option(self::OPTION_DROPIN_STATE, []);
        $dropin_path = WP_CONTENT_DIR . '/' . self::DROPIN_BASENAME;
        $have = is_file($dropin_path);
        $ours = $have && self::is_our_dropin($dropin_path);

        // WP_CACHE off?
        if (!(defined('WP_CACHE') && WP_CACHE)) {
            echo '<div class="notice notice-warning"><p><strong>FS Snapshot Cache:</strong> <code>WP_CACHE</code> is disabled. For early serving, add <code>define(\'WP_CACHE\', true);</code> to your <code>wp-config.php</code>.</p></div>';
        }

        // Drop-in write failure?
        if (!$have && !empty($state['reason']) && $state['reason']==='write-failed') {
            echo '<div class="notice notice-error"><p><strong>FS Snapshot Cache:</strong> Could not write <code>wp-content/advanced-cache.php</code>. Please make <code>wp-content</code> writable and re-activate the plugin, or create the file manually.</p></div>';
        }

        // Foreign drop-in present
        if ($have && !$ours) {
            echo '<div class="notice notice-info"><p><strong>FS Snapshot Cache:</strong> Another plugin/system manages <code>advanced-cache.php</code>. We will not overwrite it.</p></div>';
        }
    }
}

// Boot
new FS_Snapshot_Cache();
