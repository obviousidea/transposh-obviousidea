<?php
/** Optional Cloudflare purge for explicitly configured translated page URLs. */
class transposh_cloudflare_cache {
    public function __construct() {
        add_action('transposh_page_cache_invalidated', array($this, 'purge'));
    }

    public function purge($record) {
        if (!defined('TRANSPOSH_CLOUDFLARE_ZONE_ID') || !defined('TRANSPOSH_CLOUDFLARE_API_TOKEN')
            || !is_string(TRANSPOSH_CLOUDFLARE_ZONE_ID) || !is_string(TRANSPOSH_CLOUDFLARE_API_TOKEN)
            || !preg_match('/^[a-f0-9]{32}$/D', TRANSPOSH_CLOUDFLARE_ZONE_ID)
            || !preg_match('/^[A-Za-z0-9_-]+$/D', TRANSPOSH_CLOUDFLARE_API_TOKEN)
            || ($record['status'] ?? '') !== 'purged' || empty($record['languages']) || !is_array($record['languages'])) {
            return;
        }

        $configured = apply_filters('transposh_cloudflare_cache_paths', array());
        if (!is_array($configured) || !$configured) {
            return;
        }

        $home = trailingslashit(home_url('/'));
        if (wp_parse_url($home, PHP_URL_SCHEME) !== 'https' || !wp_parse_url($home, PHP_URL_HOST)) {
            return;
        }

        $urls = array();
        foreach ($configured as $language => $paths) {
            if (!preg_match('/^[A-Za-z-]{2,12}$/D', (string) $language) || !is_array($paths)
                || (!in_array('*', $record['languages'], true) && !in_array($language, $record['languages'], true))) {
                continue;
            }

            foreach ($paths as $path) {
                // Require a literal language-prefixed path made of URL-safe segments.
                // Absolute URLs, query strings, traversal, and wildcard purges are rejected.
                if (is_string($path) && preg_match('~^/' . preg_quote($language, '~')
                    . '(?:/[A-Za-z0-9_-]+)*/$~D', $path)) {
                    $urls[$home . ltrim($path, '/')] = true;
                }
            }
        }
        if (!$urls) {
            return;
        }

        $result = array(
            'utc' => gmdate('c'),
            'sequence' => (int) ($record['sequence'] ?? 0),
            'url_count' => count($urls),
            'status' => 'too_many_urls',
            'http_status' => 0,
        );
        // A single request avoids a partially purged set if a later batch fails.
        if (count($urls) <= 100) {
            $response = wp_remote_post('https://api.cloudflare.com/client/v4/zones/'
                . TRANSPOSH_CLOUDFLARE_ZONE_ID . '/purge_cache', array(
                    'timeout' => 3,
                    'redirection' => 0,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . TRANSPOSH_CLOUDFLARE_API_TOKEN,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => wp_json_encode(array('files' => array_keys($urls))),
                ));
            $result['status'] = 'transport_error';
            if (!is_wp_error($response)) {
                $result['http_status'] = (int) wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $result['status'] = $result['http_status'] === 200 && ($body['success'] ?? false) === true
                    ? 'accepted' : 'rejected';
            }
        }
        update_option('transposh_cloudflare_last_purge', $result, false);
    }
}
