<?php
/** Standalone tests for the Cloudflare purge contract; no network calls. */
function add_action($hook, $callback) { $GLOBALS['hook'] = array($hook, $callback); }
function apply_filters($hook, $value) { return $GLOBALS['paths']; }
function home_url($path) { return $GLOBALS['home']; }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function wp_parse_url($value, $part) { return parse_url($value, $part); }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return $value instanceof RuntimeException; }
function wp_remote_post($url, $args) {
    $GLOBALS['calls'][] = array($url, $args);
    return $GLOBALS['response'];
}
function wp_remote_retrieve_response_code($r) { return $r[0]; }
function wp_remote_retrieve_body($r) { return $r[1]; }
function update_option($key, $value, $autoload) { $GLOBALS['saved'] = array($key, $value, $autoload); }
function check($value, $description) {
    if (!$value) {
        fwrite(STDERR, "FAIL: $description\n");
        exit(1);
    }
    echo "PASS: $description\n";
}

require dirname(__DIR__) . '/wp/transposh_cloudflare_cache.php';
$home = 'https://www2.example.test/wordpress';
$paths = array(
    'fr' => array('/fr/product/', '/fr/product/', '/fr/other/', 'https://www.example.test/fr/product/',
        '//www.example.test/fr/product/', '/fr/../product/', '/fr/%2e%2e/product/', '/fr/product/?test=1',
        '/fr/*/', '/de/product/'),
    'de' => array('/de/product/'),
);
$calls = array();
$response = array(200, '{"success":true}');
$adapter = new transposh_cloudflare_cache();
check($hook === array('transposh_page_cache_invalidated', array($adapter, 'purge')), 'Runs after the origin cache hook');
$record = array('status' => 'purged', 'languages' => array('fr'), 'sequence' => 17);
$adapter->purge($record);
check($calls === array(), 'Opt-in is disabled without credentials');

define('TRANSPOSH_CLOUDFLARE_ZONE_ID', str_repeat('a', 32));
define('TRANSPOSH_CLOUDFLARE_API_TOKEN', 'example-token');
$adapter->purge(array_replace($record, array('status' => 'skipped')));
check($calls === array(), 'Origin cache must be purged successfully');
$adapter->purge($record);
list($endpoint, $args) = $calls[0];
$fr = array('https://www2.example.test/wordpress/fr/product/', 'https://www2.example.test/wordpress/fr/other/');
check(json_decode($args['body'], true) === array('files' => $fr), 'Only exact local FR URLs, deduplicated');
check($endpoint === 'https://api.cloudflare.com/client/v4/zones/' . str_repeat('a', 32) . '/purge_cache'
    && $args['redirection'] === 0 && $args['timeout'] === 3, 'Fixed API destination without redirects');
check($saved[0] === 'transposh_cloudflare_last_purge' && $saved[1]['sequence'] === 17
    && $saved[1]['status'] === 'accepted' && $saved[1]['url_count'] === 2 && $saved[2] === false
    && strpos(json_encode($saved), 'example-token') === false, 'Diagnostic records outcome without secrets');
$adapter->purge(array_replace($record, array('languages' => array('*'))));
check(json_decode($calls[1][1]['body'], true) === array('files' => array_merge($fr,
    array('https://www2.example.test/wordpress/de/product/'))), 'Global dictionary event still purges only configured URLs');
$response = array(429, '{"success":false}');
$adapter->purge($record);
check($saved[1]['status'] === 'rejected' && $saved[1]['http_status'] === 429, 'Rate limit is observable');
$response = new RuntimeException('connection failed');
$adapter->purge($record);
check($saved[1]['status'] === 'transport_error', 'Transport failure is observable');
$paths = array('fr' => array_map(function ($i) { return "/fr/product-$i/"; }, range(1, 101)));
$adapter->purge($record);
check(count($calls) === 4 && $saved[1]['status'] === 'too_many_urls', 'Oversized configuration sends no purge');
