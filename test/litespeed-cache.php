<?php
/** Standalone CLI contract tests; no WordPress, network, or database writes. */
if (PHP_SAPI !== 'cli') { exit(1); }
$hooks = array();
$events = array();
$checks = 0;
function add_filter($name, $callback, $priority = 10, $accepted = 1) {
    global $hooks;
    $hooks[$name][$priority][] = array($callback, $accepted);
}
function add_action($name, $callback, $priority = 10, $accepted = 1) {
    add_filter($name, $callback, $priority, $accepted);
}
function has_action($name) { global $hooks; return !empty($hooks[$name]); }
function do_action($name, ...$args) {
    global $events, $hooks;
    $events[] = array($name, $args);
    $callbacks = isset($hooks[$name]) ? $hooks[$name] : array();
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $entry) { call_user_func_array($entry[0], array_slice($args, 0, $entry[1])); }
    }
}
function apply_filters($name, $value) {
    global $hooks;
    $callbacks = isset($hooks[$name]) ? $hooks[$name] : array();
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $entry) { $value = call_user_func($entry[0], $value); }
    }
    return $value;
}
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function last_payload($name) {
    global $events;
    foreach (array_reverse($events) as $event) {
        if ($event[0] === $name) { return $event[1][0]; }
    }
    return null;
}
class LiteSpeedTestOptions {
    function is_active_language($lang) { return in_array($lang, array('en', 'fr', 'de', 'zh-CN'), true); }
}
require dirname(__DIR__) . '/wp/transposh_litespeed.php';
$tp = (object) array('edit_mode' => false, 'target_language' => 'fr', 'options' => new LiteSpeedTestOptions());
$adapter = new transposh_litespeed($tp);
// API can be registered later than Transposh's constructor.
$nocache = 0;
add_action('litespeed_control_set_nocache', function() use (&$nocache) { $nocache++; });
do_action('wp');
check($nocache === 0, 'normal visitors remain eligible for engine caching');
check(!defined('LITESPEED_NO_PAGEOPTM'), 'normal visitors retain optimization');
do_action('litespeed_tag_finalize');
check(last_payload('litespeed_tag_add') === array('transposh', 'transposh_lang_fr'), 'French page language tag');
$tp->target_language = 'en';
do_action('litespeed_tag_finalize');
check(last_payload('litespeed_tag_add') === array('transposh', 'transposh_lang_en'), 'default language is tagged');
$tp->target_language = 'zh-CN';
do_action('litespeed_tag_finalize');
check(last_payload('litespeed_tag_add') === array('transposh', 'transposh_lang_zh-CN'), 'regional language is tagged');
$tp->target_language = 'fr,bad';
do_action('litespeed_tag_finalize');
check(last_payload('litespeed_tag_add') === array('transposh'), 'invalid language never enters tag header');
do_action('transposh_translations_changed', array('fr', 'de', 'fr'));
check(last_payload('litespeed_purge') === array('transposh_lang_fr', 'transposh_lang_de'), 'mixed correction scopes and deduplicates languages');
do_action('transposh_translations_changed', array('en'));
check(last_payload('litespeed_purge') === array('transposh_lang_en'), 'source language scope retained');
foreach (array(array(), null, array('xx'), array('fr', 'de,bad')) as $unknown) {
    do_action('transposh_translations_changed', $unknown);
    check(last_payload('litespeed_purge') === array('transposh'), 'unknown scope invalidates tagged site pages');
}
do_action('transposh_translations_changed', array('de'));
check(last_payload('litespeed_purge') === array('transposh_lang_de'), 'a later write is still forwarded');
check(last_payload('litespeed_purge_all') === null, 'never flush shared backend with purge-all');
foreach (array('litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc', 'litespeed_optimize_js_excludes') as $filter) {
    $once = apply_filters($filter, array('existing.js'));
    check(in_array('existing.js', $once, true) && in_array('/transposh', $once, true) && in_array('t_jp', $once, true), 'preserve user exclusions and translation startup');
    check(in_array('jquery.js', $once, true) && in_array('jquery.min.js', $once, true), 'translation startup retains its jQuery dependency');
    check(apply_filters($filter, $once) === $once, 'script exclusions are idempotent');
}
$_GET['tpedit'] = '1';
do_action('init');
check($nocache === 1, 'requested editor blocked before permissions resolved');
check(defined('LITESPEED_NO_PAGEOPTM') && LITESPEED_NO_PAGEOPTM, 'editor disables page optimization');
unset($_GET['tpedit']);
$tp->edit_mode = true;
do_action('litespeed_control_finalize');
check($nocache === 2, 'resolved editor protected at final cache decision');
echo "PASS: $checks adapter contract checks (stubbed hooks; no server-cache proof).\n";
