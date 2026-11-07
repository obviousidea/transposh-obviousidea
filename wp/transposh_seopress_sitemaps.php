<?php
/** Include Transposh's published language URLs in SEOPress post/page sitemaps. */
class transposh_seopress_sitemaps {
    private $transposh;

    function __construct($transposh) {
        $this->transposh = $transposh;
        // These hooks are inert when SEOPress is absent. Work after its exclusions
        // and image rendering, without changing either plugin's HTML hreflang.
        add_filter('seopress_sitemaps_xml_single', array($this, 'expand'), 20);
        add_filter('seopress_sitemaps_max_posts_per_sitemap', array($this, 'limit'), 20);
        add_filter('seopress_sitemaps_no_archive_link', array($this, 'archive_link'), 20, 2);
    }

    function archive_link($xml, $post_type) {
        // WordPress's Posts page is emitted once as an archive and again as a
        // page by SEOPress. Keep the richer page entry, before language expansion.
        // Never remove archives when the page sitemap is disabled or excluded.
        if (!$this->enabled() || $post_type !== 'post'
            || get_option('show_on_front') !== 'page') {
            return $xml;
        }
        $settings = get_option('seopress_xml_sitemap_option_name', array());
        $include = $settings['seopress_xml_sitemap_post_types_list']['page'] ?? null;
        $include = is_array($include) ? ($include['include'] ?? null) : $include;
        $page = get_post((int) get_option('page_for_posts'));
        if ((string) $include !== '1' || !$page || $page->post_type !== 'page'
            || $page->post_status !== 'publish' || $page->post_password !== '') {
            return $xml;
        }
        $url = array('loc' => htmlspecialchars(urldecode(get_permalink($page->ID)), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401));
        // Respect SEOPress and other extensions' per-page exclusions.
        $url = apply_filters('seopress_sitemaps_single_url', $url, $page);
        return is_array($url) && !empty($url['loc']) ? '' : $xml;
    }

    private function languages() {
        $options = $this->transposh->options;
        $languages = array($options->default_language);
        foreach (explode(',', $options->viewable_languages) as $language) {
            $language = trim($language);
            if (transposh_consts::is_supported_language($language)
                && $options->is_active_language($language)
                && $this->transposh->database->language_has_translations($language)) {
                $languages[] = $language;
            }
        }
        return array_values(array_unique($languages));
    }

    private function enabled() {
        return apply_filters('transposh_seopress_sitemaps_enabled', true);
    }

    function limit($max) {
        if (!$this->enabled()) {
            return $max;
        }
        // Used by both the index pagination and the post/page query. Reserve
        // room for an archive entry as well as its language variants.
        return max(1, min((int) $max, (int) floor(50000 / count($this->languages())) - 1));
    }

    private function local_url($url) {
        $home = parse_url($this->transposh->home_url);
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])
            || !in_array($parts['scheme'], array('http', 'https'), true)
            || strcasecmp($parts['host'], $home['host']) !== 0
            || $parts['scheme'] !== $home['scheme']
            || ($parts['port'] ?? null) !== ($home['port'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $base = rtrim($home['path'] ?? '', '/');
        $path = $parts['path'] ?? '/';
        return !$base || $path === $base || strpos($path, $base . '/') === 0;
    }

    function expand($xml) {
        if (!$this->enabled() || !is_string($xml) || !class_exists('DOMDocument')
            || stripos($xml, '<!DOCTYPE') !== false) {
            return $xml;
        }
        $languages = $this->languages();
        if (count($languages) < 2) {
            return $xml;
        }
        $doc = new DOMDocument();
        $errors = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($xml, LIBXML_NONET)) {
                return $xml;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
        $namespace = 'http://www.sitemaps.org/schemas/sitemap/0.9';
        if (!$doc->documentElement || $doc->documentElement->localName !== 'urlset'
            || $doc->documentElement->namespaceURI !== $namespace) {
            return $xml;
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', $namespace);
        $entries = $xpath->query('/s:urlset/s:url');
        $seen = array();
        foreach ($entries as $entry) {
            $loc = $xpath->query('s:loc', $entry)->item(0);
            if ($loc) {
                $seen[$loc->textContent] = true;
            }
        }
        $pending = array();
        foreach ($entries as $entry) {
            $loc = $xpath->query('s:loc', $entry)->item(0);
            if (!$loc || !$this->local_url($loc->textContent)) {
                continue;
            }
            $url = $loc->textContent;
            // Do not expand variants emitted by another integration. This also
            // makes repeated filtering of the same XML idempotent.
            if (transposh_utils::get_language_from_url($url, $this->transposh->home_url)) {
                continue;
            }
            foreach ($languages as $language) {
                if ($this->transposh->options->is_default_language($language)) {
                    continue;
                }
                $candidate = $url;
                if ($this->transposh->options->enable_url_translate) {
                    $candidate = transposh_utils::translate_url($candidate,
                        rtrim($this->transposh->home_url, '/'), $language,
                        array($this->transposh->database, 'fetch_translation'));
                }
                $candidate = transposh_utils::rewrite_url_lang_param($candidate,
                    $this->transposh->home_url, $this->transposh->enable_permalinks_rewrite,
                    $language, false);
                if (!$this->local_url($candidate) || isset($seen[$candidate])) {
                    continue;
                }
                $clone = $entry->cloneNode(true);
                $clone_loc = $xpath->query('s:loc', $clone)->item(0);
                // DOM handles XML escaping once; nested image:loc stays intact.
                $clone_loc->textContent = $candidate;
                $pending[] = $clone;
                $seen[$candidate] = true;
            }
        }
        // Never publish a partial set when another extension exceeded the limit.
        if (!$pending || $entries->length + count($pending) > 50000) {
            return $xml;
        }
        foreach ($pending as $entry) {
            $doc->documentElement->appendChild($entry);
        }
        $result = $doc->saveXML();
        // The sitemap protocol also has a 50 MiB uncompressed size limit.
        return $result !== false && strlen($result) <= 50 * 1024 * 1024 ? $result : $xml;
    }
}
