<?php
/** WP Rocket compatibility for language-prefixed Transposh pages. */
class transposh_page_cache {
    private $transposh;
    private $languages = array();
    private $pending = false;
    private $all_languages = false;

    function __construct($transposh) {
        $this->transposh = $transposh;
        add_filter('rocket_current_url', array($this, 'canonical_current_url'), 20);
        add_filter('do_rocket_generate_caching_files', array($this, 'can_generate_cache'));
        add_filter('rocket_buffer', array($this, 'editing_buffer'), 1);
        add_action('transposh_translations_changed', array($this, 'translations_changed'));
        add_action('transposh_language_seo_status_changed', array($this, 'status_changed'));
        add_action('shutdown', array($this, 'flush_pending'), PHP_INT_MAX - 20);
    }

    function canonical_current_url($url) {
        $parameters = $_GET;
        if (function_exists('rocket_get_ignored_parameters')) {
            $parameters = array_diff_key($parameters, rocket_get_ignored_parameters());
        }
        if (!is_singular() || $parameters || !empty($this->transposh->edit_mode)
            || !$this->transposh->options->enable_permalinks) {
            return $url;
        }
        $home = home_url('/');
        $home_path = rtrim((string) wp_parse_url($home, PHP_URL_PATH), '/');
        $path = wp_parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
        $prefix = preg_quote($home_path, '~');
        if (!preg_match('~^' . $prefix . '/([a-zA-Z-]{2,12})/(.*)$~D', (string) $path, $matches)) {
            return $url;
        }
        if (!$this->transposh->options->is_active_language($matches[1])
            || $this->transposh->options->is_default_language($matches[1])) {
            return $url;
        }
        $canonical = get_permalink(get_queried_object_id());
        if (wp_parse_url($canonical, PHP_URL_HOST) !== wp_parse_url($home, PHP_URL_HOST)
            || $home_path . '/' . $matches[2] !== wp_parse_url($canonical, PHP_URL_PATH)) {
            return $url;
        }
        // Only influence Rocket's canonical validation. The cache key continues
        // to use the original language-prefixed URL, never the English URL.
        return $canonical;
    }

    function can_generate_cache($can_cache) {
        return !empty($this->transposh->edit_mode) ? false : $can_cache;
    }

    function editing_buffer($html) {
        // Rocket treats an empty result as "use the original, unoptimized HTML".
        return !empty($this->transposh->edit_mode) ? '' : $html;
    }

    function status_changed($language) {
        // a language appearing/vanishing from hreflang sets changes pages in every language
        $this->pending = true;
        $this->all_languages = true;
    }

    function translations_changed($languages) {
        $this->pending = true;
        if (!is_array($languages) || !$languages) {
            $this->all_languages = true;
            return;
        }
        foreach ($languages as $language) {
            if (!preg_match('/^[a-zA-Z-]{2,12}$/D', (string) $language)
                || !$this->transposh->options->is_active_language($language)
                || $this->transposh->options->is_default_language($language)
                || !$this->transposh->options->enable_permalinks) {
                $this->all_languages = true;
            } else {
                $this->languages[$language] = true;
            }
        }
    }

    function flush_pending() {
        if (!$this->pending) { return; }
        $this->pending = false;
        if (!function_exists('rocket_clean_domain') || !defined('WP_ROCKET_CACHE_PATH')) { return; }
        $expected = realpath(WP_CONTENT_DIR) . '/cache/wp-rocket';
        if (realpath(WP_ROCKET_CACHE_PATH) !== $expected) { return; }
        $home = trailingslashit(home_url('/'));
        $urls = array($home);
        if (!$this->all_languages && $this->languages) {
            $urls = array();
            foreach (array_keys($this->languages) as $language) {
                $urls[] = $home . $language . '/';
            }
        }
        $only_this_site = static function() use ($urls) { return $urls; };
        $method = 'rocket_clean_domain';
        add_filter('rocket_clean_domain_urls', $only_this_site, PHP_INT_MAX);
        try {
            $purged = rocket_clean_domain();
        } finally {
            remove_filter('rocket_clean_domain_urls', $only_this_site, PHP_INT_MAX);
        }
        // Rocket allows only one clean_domain call per request. If another
        // callback purged earlier, ensure our post-write invalidation still runs.
        if ($purged !== true && function_exists('rocket_rrmdir') && function_exists('_rocket_get_cache_dirs')) {
            $method = 'repeat-purge-fallback';
            $host = wp_parse_url($home, PHP_URL_HOST);
            foreach (_rocket_get_cache_dirs($host, WP_ROCKET_CACHE_PATH) as $dir) {
                $real = realpath($dir);
                if (!$real || strpos($real . '/', $expected . '/') !== 0) { continue; }
                $name = basename($real);
                if ($name !== $host && strpos($name, $host . '-') !== 0) { continue; }
                foreach ($urls as $url) {
                    $path = (string) wp_parse_url($url, PHP_URL_PATH);
                    $target = realpath($dir . $path);
                    if ($target && strpos($target . '/', $real . '/') === 0) {
                        rocket_rrmdir($target);
                    }
                }
            }
            $purged = true;
        }
        $record = array('version'=>'native-rocket-1','utc'=>gmdate('c'),
            'method'=>$method,
            'languages'=>$this->all_languages ? array('*') : array_keys($this->languages),
            'status'=>$purged === true ? 'purged' : 'skipped',
            'sequence'=>(int)get_option('transposh_page_cache_purge_sequence', 0) + 1);
        update_option('transposh_page_cache_purge_sequence', $record['sequence'], false);
        update_option('transposh_page_cache_last_purge', $record, false);
        if ($purged === true) {
            do_action('transposh_page_cache_invalidated', $record);
        }
        $this->languages = array();
        $this->all_languages = false;
    }
}
