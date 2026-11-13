<?php
/**
 * Isolated CLI regression for the engine health monitor (no WordPress, network or database).
 * php test/engine-monitor.php
 */
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    throw new RuntimeException('Run in an isolated PHP CLI process, outside WordPress.');
}
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/isolated-no-wordpress/');
$GLOBALS['opts'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['can'] = false;
$GLOBALS['mails'] = array();
function tp_logger($m, $s = 3, $t = false) {}
function apply_filters($name, $value, ...$a) { return array_key_exists($name, $GLOBALS['filters']) ? $GLOBALS['filters'][$name] : $value; }
function add_action($n, $cb, ...$a) {}
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $autoload = null) {
    if (!empty($GLOBALS['opt_throws'])) { throw new RuntimeException('database down'); }
    $GLOBALS['opts'][$k] = $v; return true;
}
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function get_site_option($k, $d = false) { return $k === 'admin_email' ? 'admin@site.invalid' : $d; }
function home_url($p = '') { return 'https://site.invalid' . $p; }
function admin_url($p = '') { return 'https://site.invalid/wp-admin/' . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_specialchars_decode($s) { return $s; }
function wp_date($f, $t) { return gmdate($f, $t); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_js($s) { return addslashes((string) $s); }
function wp_create_nonce($a) { return 'nonce'; }
function current_user_can($c) { return $GLOBALS['can']; }
function wp_next_scheduled($h) { return false; }
function wp_schedule_event($t, $r, $h) { $GLOBALS['scheduled'][] = $h; }
class transposh_translate {
    public static $next = array(true, null);
    public static $last = null;
    static function get_last_http() { return self::$last; }
    static function get_bing_translation($tl, $sl, $q) { self::$last = self::$next[1]; return self::$next[0] ? array_map(function ($x) { return 'Bonjour'; }, $q) : false; }
}
class fake_options {
    public $msn_key = 'SECRETKEY1234567890abcdef', $msn_region = 'westeurope', $msn_endpoint = 'https://api.cognitive.microsofttranslator.com/',
        $mail_to = '', $enable_autotranslate = true;
    function get_sorted_engines() { return array('b' => 1, 'g' => 1); }
}
class fake_plugin { public $options; function __construct() { $this->options = new fake_options(); } }
require_once dirname(__DIR__) . '/wp/transposh_engine_monitor.php';

$M = 'transposh_engine_monitor';
$checks = array();
$T0 = 1790000000; // a fixed "now"
$AUTH_BODY = '{"error":{"code":401001,"message":"The request is not authorized because credentials are missing or invalid."}}';

// ---- parse_error and classify
list($code, $msg) = $M::parse_error($AUTH_BODY);
$checks['parse_error_code_message'] = $code === 401001 && strpos($msg, 'not authorized') !== false;
$checks['parse_error_invalid_json'] = $M::parse_error('<html>') === array(null, '') && $M::parse_error(null) === array(null, '');
$checks['parse_error_truncated'] = strlen($M::parse_error('{"error":{"code":1,"message":"' . str_repeat('x', 500) . '"}}')[1]) === 160;
$checks['classify_401001_auth_f0_hint'] = ($r = $M::classify('b', 401, 401001, false))[0] === 'auth' && strpos($r[1], 'F0') !== false;
$checks['classify_403001_quota'] = $M::classify('b', 403, 403001, false)[0] === 'quota';
$checks['classify_429_throttle'] = $M::classify('b', 429, 429000, false)[0] === 'throttle';
$checks['classify_503_server'] = $M::classify('b', 503, null, false)[0] === 'server';
$checks['classify_400_request'] = $M::classify('b', 400, 400036, false)[0] === 'request';
$checks['classify_curl_network'] = $M::classify('b', 0, null, true)[0] === 'network';
$checks['classify_unconfigured_config'] = $M::classify('b', 0, null, false, false)[0] === 'config';
$checks['classify_nothing_unknown'] = $M::classify('b', 0, null, false)[0] === 'unknown';
$checks['classify_http_family_without_api_code'] = $M::classify('b', 401, null, false)[0] === 'auth';

// ---- counters
$ok = array('engine' => 'b', 'ok' => true, 'items' => 3, 'chars' => 40, 'source' => 'visitor', 'lang' => 'fr');
$bad = array('engine' => 'b', 'ok' => false, 'items' => 2, 'chars' => 25, 'source' => 'admin', 'lang' => 'de', 'class' => 'auth',
    'http' => 401, 'code' => 401001, 'message' => 'bad key', 'hint' => 'check');
list($s, $h, $ev) = $M::apply_call(array(), array(), $ok, $T0);
list($s, $h, $ev) = $M::apply_call($s, $h, $bad, $T0 + 5);
$d = $s[gmdate('Y-m-d', $T0)]['b'];
$checks['counts_calls_ok_fail'] = $d['calls'] === 2 && $d['ok'] === 1 && $d['fail'] === 1;
$checks['counts_items_chars'] = $d['items'] === 5 && $d['items_ok'] === 3 && $d['chars'] === 65 && $d['chars_ok'] === 40;
$checks['counts_sources_and_langs'] = $d['src'] === array('visitor' => 1, 'admin' => 1) && $d['langs']['fr'] === array('i' => 3, 'k' => 3) && $d['langs']['de'] === array('i' => 2, 'k' => 0);
$checks['one_failure_no_alert'] = $ev === null && $h['b']['streak'] === 1 && empty($h['b']['incident']);
$old = $M::apply_call(array(), array(), $ok, $T0 - 15 * 86400)[0];
$s2 = $M::apply_call($old, array(), $ok, $T0)[0];
$checks['retention_prunes_old_days'] = count($s2) === 1 && isset($s2[gmdate('Y-m-d', $T0)]);
$s3 = $M::apply_call($M::apply_call(array(), array(), $ok, $T0 - 13 * 86400)[0], array(), $ok, $T0)[0];
$checks['retention_keeps_recent_days'] = count($s3) === 2;
$off = $M::apply_call(array(), array(), $ok, strtotime('2026-11-13 23:30:00 UTC'), 7200)[0];
$checks['site_day_uses_offset'] = array_keys($off) === array('2026-11-14');

// ---- alert rules
$h = array(); $s = array(); $events = array();
for ($i = 0; $i < 3; $i++) {
    list($s, $h, $ev) = $M::apply_call($s, $h, $bad, $T0 + $i * 60);
    $events[] = $ev;
}
$checks['alert_on_third_failure_only'] = $events[0] === null && $events[1] === null && $events[2]['type'] === 'alert' && $events[2]['count'] === 3 && $events[2]['code'] === 401001;
$checks['alert_incident_recorded'] = $h['b']['incident']['class'] === 'auth' && $h['b']['incident']['emailed'] === true && $h['b']['incident']['since'] === $T0;
list($s, $h, $ev) = $M::apply_call($s, $h, $bad, $T0 + 600);
$checks['no_second_alert_within_cooldown'] = $ev === null && $h['b']['streak'] === 4;
list($s, $h, $ev) = $M::apply_call($s, $h, $bad, $T0 + 86400 + 10); // 86290 s after the alert: still in the cooldown
$checks['no_alert_just_before_cooldown_ends'] = $ev === null;
list($s, $h, $ev) = $M::apply_call($s, $h, $bad, $T0 + 86400 + 200);
$checks['alert_again_after_cooldown'] = $ev !== null && $ev['type'] === 'alert' && $ev['since'] === $T0;
$srv = array('class' => 'server', 'http' => 503, 'code' => null) + $bad;
list($s, $h, $ev) = $M::apply_call($s, $h, $srv, $T0 + 86400 + 220);
$checks['class_change_alerts_at_once'] = $ev !== null && $ev['class'] === 'server';
list($s, $h, $ev) = $M::apply_call($s, $h, $ok, $T0 + 90000);
$checks['recovery_event_after_alert'] = $ev !== null && $ev['type'] === 'recovered' && $ev['emailed'] === true && empty($h['b']['incident']) && $h['b']['streak'] === 0;
list($s2, $h2, $ev) = $M::apply_call(array(), array(), $ok, $T0);
$checks['success_without_incident_silent'] = $ev === null;
$h = array(); $ev = null;
for ($i = 0; $i < 6; $i++) {
    list($s, $h, $ev) = $M::apply_call(array(), $h, array('class' => 'request', 'http' => 400, 'code' => 400036) + $bad, $T0 + $i);
}
$checks['request_errors_never_alert'] = $ev === null && empty($h['b']['incident']) && $h['b']['streak'] === 6;
$test_fail = array('source' => 'health', 'class' => 'request') + $bad;
list($s, $h, $ev) = $M::apply_call(array(), array(), $test_fail, $T0);
$checks['failed_health_test_alerts_at_once'] = $ev !== null && $ev['type'] === 'alert' && $ev['count'] === 1;
$g = array('engine' => 'g') + $bad;
$h = array(); $ev = null;
for ($i = 0; $i < 5; $i++) {
    list($s, $h, $ev) = $M::apply_call(array(), $h, $g, $T0 + $i);
}
$checks['non_alert_engine_counted_not_alerted'] = $ev === null && empty($h['g']['incident']) && $h['g']['streak'] === 5;
list($s, $h, $ev) = $M::apply_call(array(), array(), array('engine' => 'g') + $bad, $T0, 0, array('b', 'g'));
$checks['alert_engines_are_configurable'] = $ev === null; // streak 1 only
$got = null;
for ($i = 0; $i < 3; $i++) {
    list($s, $h, $ev) = $M::apply_call($s, $h, array('engine' => 'g') + $bad, $T0 + $i, 0, array('b', 'g'));
    $got = $got ?: $ev;
}
$checks['alert_engines_third_failure'] = $got !== null && $got['engine'] === 'g' && $got['count'] === 3;

// ---- summary
$s = array();
for ($i = 0; $i < 9; $i++) {
    $s = $M::apply_call($s, array(), array('engine' => 'b', 'ok' => true, 'items' => 10, 'chars' => 100, 'source' => 'visitor', 'lang' => 'fr'), $T0 - $i * 86400)[0];
}
$sum = $M::summarize($s, $T0);
$checks['summary_today_yesterday'] = $sum['today']['b']['items'] === 10 && $sum['yesterday']['b']['items'] === 10;
$checks['summary_last7_is_seven_days'] = $sum['last7']['b']['items'] === 70 && $sum['last7']['b']['chars'] === 700 && count($sum['days']) === 7;
$checks['summary_empty'] = $M::summarize(array(), $T0)['last7'] === array();

// ---- mail
$ev = array('type' => 'alert', 'engine' => 'b', 'class' => 'auth', 'code' => 401001, 'http' => 401, 'msg' => 'The request is not authorized',
    'hint' => 'Check the key.', 'since' => $T0, 'count' => 3, 'now' => $T0 + 120);
list($subject, $body) = $M::compose_mail($ev, 'site.invalid', 'https://site.invalid/wp-admin/admin.php?page=tp_engines');
$checks['mail_subject_has_engine_code_site'] = strpos($subject, 'Microsoft Translator') !== false && strpos($subject, '401001') !== false && strpos($subject, 'site.invalid') !== false;
$checks['mail_body_has_hint_link_azure'] = strpos($body, 'Check the key.') !== false && strpos($body, 'tp_engines') !== false && strpos($body, 'Azure') !== false;
list($rs, $rb) = $M::compose_mail(array('type' => 'recovered', 'engine' => 'b', 'since' => $T0, 'now' => $T0 + 3600, 'class' => 'auth', 'emailed' => true), 'site.invalid', 'u');
$checks['mail_recovered'] = strpos($rs, 'works again') !== false && strpos($rb, 'ended') !== false;

// ---- config rules
$base = array('autotranslate' => true, 'bing_first' => true, 'bing_key' => true, 'endpoint' => 'https://x', 'region' => 'westeurope', 'cron_disabled' => false,
    'last_test' => $T0 - 3600, 'cf_status' => 'accepted', 'alert_source' => 'alert addresses of this panel', 'now' => $T0);
$checks['config_clean_has_no_issue'] = $M::evaluate_config($base) === array();
$checks['config_missing_key_is_error'] = $M::evaluate_config(array('bing_key' => false) + $base)[0][0] === 'error';
$checks['config_http_endpoint_warns'] = $M::evaluate_config(array('endpoint' => 'http://x') + $base)[0][0] === 'warn';
$checks['config_empty_region_info'] = $M::evaluate_config(array('region' => '') + $base)[0][0] === 'info';
$checks['config_cron_disabled_warns'] = $M::evaluate_config(array('cron_disabled' => true) + $base)[0][0] === 'warn';
$checks['config_stale_test_warns'] = $M::evaluate_config(array('last_test' => $T0 - 50 * 3600) + $base)[0][0] === 'warn';
$checks['config_never_tested_info'] = $M::evaluate_config(array('last_test' => null) + $base)[0][0] === 'info';
$checks['config_cloudflare_rejected_warns'] = $M::evaluate_config(array('cf_status' => 'rejected') + $base)[0][0] === 'warn';

// ---- instance: end to end with a fake plugin, a clock and a mail catcher
$M::$clock = function () { return $GLOBALS['T']; };
$M::$mailer = function ($to, $subject, $body) { $GLOBALS['mails'][] = array($to, $subject, $body); return $GLOBALS['mail_ok'] ?? true; };
$GLOBALS['T'] = $T0; $GLOBALS['mail_ok'] = true;
$plugin = new fake_plugin();
$mon = new transposh_engine_monitor($plugin);
$mon->schedule();
$checks['schedule_registers_event'] = ($GLOBALS['scheduled'] ?? array()) === array('transposh_engine_health_event');

transposh_translate::$last = array('code' => 401, 'body' => $AUTH_BODY);
for ($i = 0; $i < 3; $i++) {
    $GLOBALS['T'] = $T0 + $i * 30;
    $mon->record_proxy_call('b', 'fr', array(urlencode('Hello world'), urlencode('Bye')), false, false);
}
$checks['proxy_three_failures_one_mail'] = count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === array('admin@site.invalid')
    && strpos($GLOBALS['mails'][0][1], '401001') !== false;
$state = serialize($GLOBALS['opts']) . $GLOBALS['mails'][0][1] . $GLOBALS['mails'][0][2];
$checks['no_key_or_text_stored_or_mailed'] = strpos($state, 'SECRETKEY') === false && strpos($state, 'Hello world') === false;
$checks['chars_counted_from_decoded_text'] = $GLOBALS['opts']['transposh_engine_stats'][gmdate('Y-m-d', $T0)]['b']['chars'] === 3 * 14;
$GLOBALS['T'] = $T0 + 200;
$mon->record_proxy_call('b', 'fr', array(urlencode('Hello')), array('Bonjour'), false);
$checks['recovery_sends_second_mail'] = count($GLOBALS['mails']) === 2 && strpos($GLOBALS['mails'][1][1], 'works again') !== false;
$checks['visitor_source_when_not_admin'] = isset($GLOBALS['opts']['transposh_engine_stats'][gmdate('Y-m-d', $T0)]['b']['src']['visitor']);
$GLOBALS['can'] = true;
$mon->record_proxy_call('b', 'fr', array(urlencode('Hello')), array('Bonjour'), false);
$mon->record_proxy_call('b', 'fr', 'Hello', 'Bonjour', true);
$GLOBALS['can'] = false;
$mon->record_rest_call('b', 'de', array(urlencode('Hi')), array('Hallo'));
$src = $GLOBALS['opts']['transposh_engine_stats'][gmdate('Y-m-d', $T0)]['b']['src'];
$checks['sources_admin_suggest_rest'] = ($src['admin'] ?? 0) === 1 && ($src['suggest'] ?? 0) === 1 && ($src['rest'] ?? 0) === 1;
$checks['result_count_mismatch_is_failure'] = (function () use ($mon, $T0) {
    $before = $GLOBALS['opts']['transposh_engine_health']['b']['streak'];
    $mon->record_proxy_call('b', 'fr', array('a', 'b'), array('only one'), false);
    return $GLOBALS['opts']['transposh_engine_health']['b']['streak'] === $before + 1;
})();

// run_test: success then failure
$GLOBALS['mails'] = array(); $GLOBALS['opts'] = array();
transposh_translate::$next = array(true, null);
$GLOBALS['T'] = $T0;
$r = $mon->run_test('b');
$checks['run_test_success'] = $r['ok'] === true && $GLOBALS['opts']['transposh_engine_health']['_test']['ok'] === true && $GLOBALS['opts']['transposh_engine_health']['b']['last_ok'] === $T0;
$checks['run_test_counts_five_chars'] = $GLOBALS['opts']['transposh_engine_stats'][gmdate('Y-m-d', $T0)]['b']['chars'] === 5 && !empty($GLOBALS['opts']['transposh_engine_stats'][gmdate('Y-m-d', $T0)]['b']['src']['health']);
transposh_translate::$next = array(false, array('code' => 401, 'body' => $AUTH_BODY));
$GLOBALS['T'] = $T0 + 86400;
$r = $mon->run_test('b');
$checks['run_test_failure_alerts_at_once'] = $r['ok'] === false && $r['class'] === 'auth' && $r['code'] === 401001 && count($GLOBALS['mails']) === 1;
$plugin->options->msn_key = '';
$mails_before = count($GLOBALS['mails']);
$r = $mon->run_test('b');
$checks['run_test_without_key_reports_config_no_mail'] = $r['ok'] === false && $r['class'] === 'config' && count($GLOBALS['mails']) === $mails_before;
$plugin->options->msn_key = 'SECRETKEY1234567890abcdef';
$checks['run_test_other_engine_refused'] = $mon->run_test('g')['ok'] === false;

// mail failure and database failure never throw
$GLOBALS['opts'] = array(); $GLOBALS['mail_ok'] = false; $GLOBALS['mails'] = array();
$GLOBALS['T'] = $T0 + 5 * 86400;
transposh_translate::$last = array('code' => 401, 'body' => $AUTH_BODY);
for ($i = 0; $i < 3; $i++) { $mon->record_proxy_call('b', 'fr', array('a'), false); }
$checks['mail_failure_recorded_not_thrown'] = $GLOBALS['opts']['transposh_engine_health']['_mail']['ok'] === false && $GLOBALS['opts']['transposh_engine_health']['_mail']['type'] === 'alert';
$GLOBALS['mail_ok'] = true;
$GLOBALS['opt_throws'] = true;
$threw = false;
try { $mon->record_proxy_call('b', 'fr', array('a'), false); } catch (Throwable $e) { $threw = true; }
$GLOBALS['opt_throws'] = false;
$checks['database_failure_never_breaks_translation'] = $threw === false;

// kill switch
$GLOBALS['opts'] = array();
$GLOBALS['filters']['transposh_engine_monitor_enabled'] = false;
$mon->record_proxy_call('b', 'fr', array('a'), false);
$checks['kill_switch_records_nothing'] = $GLOBALS['opts'] === array();
unset($GLOBALS['filters']['transposh_engine_monitor_enabled']);

// panel escaping and content
$GLOBALS['opts'] = array(); $GLOBALS['mails'] = array();
$GLOBALS['T'] = $T0;
transposh_translate::$last = array('code' => 401, 'body' => '{"error":{"code":401001,"message":"<script>alert(1)</script>"}}');
for ($i = 0; $i < 3; $i++) { $mon->record_proxy_call('b', 'fr', array('a'), false); }
ob_start(); $mon->render_panel(); $html = ob_get_clean();
$checks['panel_shows_failing_state'] = strpos($html, 'Failing') !== false && strpos($html, 'error 401001') !== false && strpos($html, 'Engine health') !== false;
$checks['panel_escapes_engine_message'] = strpos($html, '<script>alert(1)</script>') === false;
$checks['panel_has_both_buttons_and_recipient'] = strpos($html, 'data-tp-engine-action="test"') !== false && strpos($html, 'data-tp-engine-action="mail"') !== false && strpos($html, 'admin@site.invalid') !== false;
$checks['panel_never_prints_key'] = strpos($html, 'SECRETKEY') === false;
$payload = $mon->status_payload();
$checks['status_payload_shape'] = isset($payload['summary'], $payload['health'], $payload['config_issues']) && strpos(json_encode($payload), 'SECRETKEY') === false;

// ---- recipients
list($v, $bad_entries) = $M::parse_recipients('a@x.com, B@y.org; a@X.com  c@z.io bad');
$checks['parse_recipients_valid_dedup_invalid'] = $v === array('a@x.com', 'B@y.org', 'c@z.io') && $bad_entries === array('bad');
$checks['parse_recipients_limit_five'] = count($M::parse_recipients('a@x.io b@x.io c@x.io d@x.io e@x.io f@x.io g@x.io')[0]) === 5;
$checks['parse_recipients_empty'] = $M::parse_recipients('') === array(array(), array());
$GLOBALS['opts'] = array(); $GLOBALS['mails'] = array();
$checks['recipients_default_site_admin'] = $mon->recipients() === array(array('admin@site.invalid'), 'site administrator address');
$plugin->options->mail_to = 'philipp@site.invalid';
$checks['recipients_transposh_mail_to_first'] = $mon->recipients() === array(array('philipp@site.invalid'), 'Transposh "Mail to" setting');
$r = $mon->save_recipients('fabrice@site.invalid, philipp@site.invalid');
$checks['save_recipients_stores_list'] = $r['ok'] === true && $GLOBALS['opts']['transposh_engine_alert_to'] === 'fabrice@site.invalid, philipp@site.invalid'
    && $mon->recipients()[1] === 'alert addresses of this panel';
$r = $mon->save_recipients('fabrice@site.invalid, not-an-address');
$checks['save_recipients_rejects_invalid_keeps_previous'] = $r['ok'] === false && $r['invalid'] === array('not-an-address')
    && $GLOBALS['opts']['transposh_engine_alert_to'] === 'fabrice@site.invalid, philipp@site.invalid';
$GLOBALS['T'] = $T0 + 10 * 86400;
transposh_translate::$last = array('code' => 401, 'body' => $AUTH_BODY);
for ($i = 0; $i < 3; $i++) { $mon->record_proxy_call('b', 'fr', array('a'), false); }
$checks['alert_goes_to_every_recipient'] = count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === array('fabrice@site.invalid', 'philipp@site.invalid');
$checks['mail_record_lists_recipients'] = $GLOBALS['opts']['transposh_engine_health']['_mail']['to'] === 'fabrice@site.invalid, philipp@site.invalid';
ob_start(); $mon->render_panel(); $html = ob_get_clean();
$checks['panel_has_address_field_and_current_list'] = strpos($html, 'id="tp_engine_alert_to"') !== false && strpos($html, 'value="fabrice@site.invalid, philipp@site.invalid"') !== false
    && strpos($html, 'alert addresses of this panel') !== false && strpos($html, 'data-tp-engine-action="save"') !== false;
$payload = $mon->status_payload();
$checks['status_payload_lists_recipients'] = $payload['recipients'] === array('fabrice@site.invalid', 'philipp@site.invalid') && $payload['recipients_source'] === 'alert addresses of this panel';
$r = $mon->save_recipients('');
$checks['save_empty_returns_to_defaults'] = $r['ok'] === true && !isset($GLOBALS['opts']['transposh_engine_alert_to']) && $mon->recipients()[1] === 'Transposh "Mail to" setting';
$checks['config_alert_source_site_admin_is_info'] = $M::evaluate_config(array('alert_source' => 'site administrator address') + $base)[0][0] === 'info';
$checks['config_no_recipient_is_error'] = $M::evaluate_config(array('alert_source' => 'none') + $base)[0][0] === 'error';
$plugin->options->mail_to = '';
// the wp-config constant wins and blocks the field (last: a constant cannot be undefined)
define('TRANSPOSH_ENGINE_ALERT_TO', 'const@site.invalid');
$checks['constant_wins'] = $mon->recipients() === array(array('const@site.invalid'), 'wp-config.php (TRANSPOSH_ENGINE_ALERT_TO)');
$checks['constant_blocks_saving'] = $mon->save_recipients('x@site.invalid')['ok'] === false && !isset($GLOBALS['opts']['transposh_engine_alert_to']);

$fail = 0;
foreach ($checks as $k => $v) { echo ($v ? 'PASS ' : 'FAIL ') . $k . "\n"; if (!$v) { $fail++; } }
echo count($checks) - $fail . '/' . count($checks) . " passed\n";
exit($fail ? 1 : 0);
