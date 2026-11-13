<?php
/**
 * Isolated CLI regression for SEO <head> phrase collection (no WordPress, network or DB).
 * php test/head-phrases.php [--fixture=/path/to/page.html]
 *
 * Parity check: every phrase the parser asks a translation for while rendering
 * the <head> must be part of transposh_postpublish::extract_head_phrases(),
 * otherwise "translate all" would leave it untranslated.
 */
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    throw new RuntimeException('Run in an isolated PHP CLI process, outside WordPress.');
}
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', __DIR__ . '/isolated-no-wordpress/');
function tp_logger($message, $severity = 3, $trace = false) {}
function apply_filters($name, $value, ...$args) { return $value; }
function add_action($name, $callback, ...$args) {}
function add_filter($name, $callback, ...$args) {}
function get_option($key, $default = false) { return $default; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function home_url($path = '') { return 'https://unit.invalid' . $path; }
class transposh_translate { static function getProtectedTerms() { return array(); } }
require_once dirname(__DIR__) . '/core/parser.php';
require_once dirname(__DIR__) . '/wp/transposh_postpublish.php';

$checks = array();
$args = getopt('', array('fixture:'));

$synthetic = <<<'HTML'
<!doctype html><html lang="en-US"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Extract Lightroom Previews for Lost Photos | LrToolbox</title>
<meta name="description" content="Extract available JPEG previews from a Lightroom Classic catalog when originals are lost.">
<meta name="robots" content="index, follow, max-snippet:-1">
<meta property="og:url" content="https://unit.invalid/lrtoolbox/">
<meta property="og:locale" content="en_US">
<meta property="og:title" content="Recover what Lightroom still has">
<meta property="og:description" content="Previews, ratings &amp; reports from your catalog.">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="LrToolbox for Lightroom Classic">
<link rel="canonical" href="https://unit.invalid/lrtoolbox/">
<style>.x{content:"Not a phrase"}</style>
<script>var notAPhrase = "Do not translate me";</script>
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebPage","headline":"LrToolbox headline","description":"Schema description with an apostrophe's quote","url":"https://unit.invalid/lrtoolbox/"},{"@type":"Organization","name":"ObviousIdea"}]}</script>
</head><body><p>Body text is collected elsewhere</p></body></html>
HTML;

function render_requests($html) {
    $asked = array();
    $p = new tp_parser();
    $p->lang = 'fr'; $p->is_auto_translate = false; $p->is_edit_mode = false; $p->allow_ad = false;
    $p->fetch_translate_func = function ($text, $lang) use (&$asked) { $asked[$text] = $text; return array(null, null); };
    $p->url_rewrite_func = function ($url) { return $url; };
    preg_match('~<head\b[^>]*>.*?</head>~is', $html, $m);
    $p->fix_html('<html>' . $m[0] . '<body></body></html>');
    return $asked;
}

$got = transposh_postpublish::extract_head_phrases($synthetic);
foreach (array(
    'title' => 'Extract Lightroom Previews for Lost Photos',
    'description' => 'Extract available JPEG previews from a Lightroom Classic catalog when originals are lost',
    'og_title' => 'Recover what Lightroom still has',
    'twitter_title' => 'LrToolbox for Lightroom Classic',
    'jsonld_headline' => 'LrToolbox headline',
    'jsonld_description' => "Schema description with an apostrophe's quote",
) as $name => $needle) {
    $found = false;
    foreach ($got as $phrase) { if (strpos(html_entity_decode($phrase, ENT_QUOTES), $needle) !== false) { $found = true; } }
    $checks['collects_' . $name] = $found;
}
$joined = implode("\n", $got);
$checks['ignores_robots'] = strpos($joined, 'max-snippet') === false;
$checks['ignores_og_url'] = strpos($joined, 'unit.invalid') === false;
$checks['ignores_inline_script'] = strpos($joined, 'Do not translate me') === false;
$checks['ignores_style'] = strpos($joined, 'Not a phrase') === false;
$checks['ignores_body'] = strpos($joined, 'Body text') === false;
$checks['ignores_jsonld_name'] = !in_array('ObviousIdea', $got, true);
$checks['no_head_returns_null'] = transposh_postpublish::extract_head_phrases('<p>no head</p>') === null;
$checks['non_string_returns_null'] = transposh_postpublish::extract_head_phrases(null) === null;
$checks['empty_head_returns_array'] = transposh_postpublish::extract_head_phrases('<html><head></head></html>') === array();

// Codex review cases: literal UTF-8 NBSP, unquoted/varied JSON-LD attributes, several scripts
$nbsp = "\xC2\xA0";
$tricky = '<html><head><title>Light' . $nbsp . 'Image Resizer : batch photo resizer</title>'
    . '<meta name="description" content="Resize' . $nbsp . 'photos in batch.">'
    . '<script type=application/ld+json>{"headline":"Unquoted' . $nbsp . 'type headline"}</script>'
    . "<script data-x='1' type='application/ld+json'>{\"@graph\":[{\"description\":\"Second script description\"}]}</script>"
    . '</head><body></body></html>';
$fixtures_extra = array('tricky' => $tricky);
$got = transposh_postpublish::extract_head_phrases($tricky);
$checks['nbsp_normalised_like_fix_html'] = !preg_grep('/\xC2\xA0/', $got);
$checks['jsonld_unquoted_type_collected'] = in_array('Unquoted type headline', $got, true);
$checks['jsonld_second_script_collected'] = in_array('Second script description', $got, true);
$checks['invalid_jsonld_ignored'] = is_array(transposh_postpublish::extract_head_phrases('<html><head><script type="application/ld+json">{bad</script><title>Ok title</title></head></html>'));

// Parity on the synthetic head and on an optional real page
$fixtures = array('synthetic' => $synthetic) + $fixtures_extra;
if (!empty($args['fixture'])) { $fixtures['fixture'] = file_get_contents($args['fixture']); }
foreach ($fixtures as $name => $html) {
    $asked = render_requests($html);
    $collected = transposh_postpublish::extract_head_phrases($html);
    $missing = array_diff_key($asked, $collected);
    $checks['parity_' . $name . '_' . count($asked) . '_asked'] = count($asked) > 0 && !$missing;
    if ($missing) { fwrite(STDERR, "[$name] asked at render but not collected:\n  " . implode("\n  ", array_slice($missing, 0, 15)) . "\n"); }
}

$fail = 0;
foreach ($checks as $k => $ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $k . "\n"; if (!$ok) { $fail++; } }
echo count($checks) - $fail . '/' . count($checks) . " passed\n";
exit($fail ? 1 : 0);
