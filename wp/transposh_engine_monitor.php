<?php

/*
 * Transposh v%VERSION%
 * http://transposh.org/
 *
 * Copyright %YEAR%, Team Transposh
 * Licensed under the GPL Version 2 or higher.
 * http://transposh.org/license
 *
 * Date: %DATE%
 */

/*
 * Machine translation health: daily counters, failure detection and e-mail alerts.
 *
 * Stores aggregate counts and the last error code/message only: never the translated
 * texts, API keys or visitor data. The logic is in static methods without WordPress calls
 * so it can be tested in isolation (test/engine-monitor.php).
 *
 * Kill switch: define('TRANSPOSH_ENGINE_MONITOR', false); or the filter transposh_engine_monitor_enabled.
 */

class transposh_engine_monitor {

    const OPT_STATS = 'transposh_engine_stats';
    const OPT_HEALTH = 'transposh_engine_health';
    const CRON_EVENT = 'transposh_engine_health_event';
    /** days of counters kept */
    const KEEP_DAYS = 14;
    /** consecutive failures before an alert (a failed scheduled or manual test alerts at once) */
    const THRESHOLD = 3;
    /** seconds between two e-mails for the same engine and error class */
    const COOLDOWN = 86400;

    /** @var callable|null test hook: returns the current timestamp */
    public static $clock = null;
    /** @var callable|null test hook: function($to, $subject, $body) returning bool, replaces wp_mail */
    public static $mailer = null;

    /** @var transposh_plugin */
    private $transposh;

    function __construct(&$transposh) {
        $this->transposh = &$transposh;
        if (!self::enabled()) {
            return;
        }
        add_action(self::CRON_EVENT, array($this, 'run_daily_check'));
        add_action('init', array($this, 'schedule'));
        // the method name contains "tp_notices": Transposh keeps only such notices on its own pages
        add_action('admin_notices', array($this, 'tp_notices_engine'));
        add_action('wp_ajax_tp_engine_health', array($this, 'on_ajax_health'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /* ------------------------------------------------------------------ pure logic */

    static function enabled() {
        if (defined('TRANSPOSH_ENGINE_MONITOR') && !TRANSPOSH_ENGINE_MONITOR) {
            return false;
        }
        return (bool) apply_filters('transposh_engine_monitor_enabled', true);
    }

    static function now() {
        return self::$clock ? (int) call_user_func(self::$clock) : time();
    }

    static function offset() {
        return (int) round((float) get_option('gmt_offset', 0) * 3600);
    }

    static function engine_names() {
        return array('b' => 'Microsoft Translator', 'g' => 'Google', 'o' => 'Ollama', 'a' => 'Apertium',
            'y' => 'Yandex', 'u' => 'Baidu', 'l' => 'LibreTranslate');
    }

    /**
     * Error code and message of a JSON error body (Microsoft Translator: {"error":{"code":401001,"message":"..."}})
     * @return array array(int|null code, string message up to 160 chars)
     */
    static function parse_error($body) {
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data['error'])) {
            return array(null, '');
        }
        $err = $data['error'];
        $code = (is_array($err) && isset($err['code']) && is_numeric($err['code'])) ? (int) $err['code'] : null;
        $msg = is_array($err) ? (string) ($err['message'] ?? '') : (string) $err;
        $msg = preg_replace('/\s+/', ' ', trim($msg));
        return array($code, function_exists('mb_substr') ? mb_substr($msg, 0, 160) : substr($msg, 0, 160));
    }

    /**
     * Group an error: auth, quota, throttle, server, request, network, config or unknown.
     * @return array array(class, hint)
     */
    static function classify($engine, $http, $api_code, $curl_failed, $configured = true) {
        if (!$configured) {
            return array('config', 'No API key is set for this engine. Add it on the Translation Engines tab or in wp-config.php (TRANSPOSH_MSN_KEY).');
        }
        if ($curl_failed) {
            return array('network', 'The server could not reach the engine (timeout, DNS or firewall). Usually temporary.');
        }
        $code = (int) $api_code;
        $family = $code >= 100000 ? intdiv($code, 1000) : (int) $http;
        if ($family === 401) {
            $hint = ($engine === 'b')
                ? 'The credentials are refused. Check the key and the region, and that the Azure resource is active. A free (F0) resource that used its monthly quota can also be refused this way.'
                : 'The credentials are refused. Check the key of this engine.';
            return array('auth', $hint);
        }
        if ($family === 403) {
            return array('quota', 'Access or quota refused. For a free (F0) Azure resource the monthly quota is probably used up: wait for the reset or move the resource to a paid tier (S1).');
        }
        if ($family === 429) {
            return array('throttle', 'Too many requests: the engine asks to slow down. Usually temporary.');
        }
        if ($family >= 500) {
            return array('server', 'The engine reported a server error. Usually temporary.');
        }
        if ($family >= 400) {
            return array('request', 'The engine refused the request (unsupported language or text).');
        }
        return array('unknown', 'The engine did not return a usable answer.');
    }

    /**
     * Add one engine call to the daily counters and the health state.
     * @param array $stats day => engine => counters
     * @param array $health engine => state
     * @param array $c call: engine, ok, items, chars, source, lang, http, code, class, hint, message
     * @param int $now timestamp
     * @param int $offset seconds added to UTC to get the site day
     * @param array $alert_engines engines allowed to raise an alert
     * @return array array($stats, $health, $event|null)
     */
    static function apply_call(array $stats, array $health, array $c, $now, $offset = 0, array $alert_engines = array('b')) {
        $e = (string) $c['engine'];
        $day = gmdate('Y-m-d', $now + $offset);
        $d = $stats[$day][$e] ?? array('calls' => 0, 'ok' => 0, 'fail' => 0, 'items' => 0, 'items_ok' => 0,
            'chars' => 0, 'chars_ok' => 0, 'src' => array(), 'langs' => array());
        $ok = !empty($c['ok']);
        $items = max(0, (int) ($c['items'] ?? 0));
        $chars = max(0, (int) ($c['chars'] ?? 0));
        $d['calls']++;
        $d[$ok ? 'ok' : 'fail']++;
        $d['items'] += $items;
        $d['chars'] += $chars;
        if ($ok) {
            $d['items_ok'] += $items;
            $d['chars_ok'] += $chars;
        }
        $src = (string) ($c['source'] ?? 'visitor');
        $d['src'][$src] = ($d['src'][$src] ?? 0) + 1;
        $lang = (string) ($c['lang'] ?? '');
        if ($lang !== '') {
            $l = $d['langs'][$lang] ?? array('i' => 0, 'k' => 0);
            $l['i'] += $items;
            $l['k'] += $ok ? $items : 0;
            $d['langs'][$lang] = $l;
        }
        $stats[$day][$e] = $d;
        $cutoff = gmdate('Y-m-d', $now + $offset - self::KEEP_DAYS * 86400);
        foreach (array_keys($stats) as $k) {
            if ($k < $cutoff) {
                unset($stats[$k]);
            }
        }
        ksort($stats);

        $h = $health[$e] ?? array('streak' => 0);
        $event = null;
        if ($ok) {
            $inc = $h['incident'] ?? null;
            $h['streak'] = 0;
            $h['last_ok'] = $now;
            unset($h['streak_since'], $h['incident']);
            if ($inc && in_array($e, $alert_engines, true)) {
                $event = array('type' => 'recovered', 'engine' => $e, 'since' => (int) $inc['since'], 'now' => $now,
                    'class' => (string) $inc['class'], 'emailed' => !empty($inc['emailed']));
            }
        } else {
            $h['streak'] = (int) ($h['streak'] ?? 0) + 1;
            if ($h['streak'] === 1) {
                $h['streak_since'] = $now;
            }
            $h['last_fail'] = $now;
            $class = (string) ($c['class'] ?? 'unknown');
            $h['last_error'] = array('t' => $now, 'http' => $c['http'] ?? null, 'code' => $c['code'] ?? null,
                'class' => $class, 'msg' => (string) ($c['message'] ?? ''), 'src' => $src);
            $threshold = ($src === 'health') ? 1 : self::THRESHOLD;
            // a refused text or language ("request") or an unreadable answer ("unknown") is not an outage:
            // counted, but only a failed test can alert for them
            $alertable = $src === 'health' || !in_array($class, array('request', 'unknown'), true);
            if ($alertable && in_array($e, $alert_engines, true) && $h['streak'] >= $threshold) {
                $inc = $h['incident'] ?? null;
                $emailed = ($inc && $inc['class'] === $class) ? !empty($inc['emailed']) : false;
                $h['incident'] = array('since' => ($inc && $inc['class'] === $class) ? (int) $inc['since'] : (int) ($h['streak_since'] ?? $now),
                    'class' => $class, 'code' => $c['code'] ?? null, 'http' => $c['http'] ?? null,
                    'msg' => (string) ($c['message'] ?? ''), 'emailed' => $emailed);
                $last = (int) ($h['alerts'][$class] ?? 0);
                if ($now - $last >= self::COOLDOWN) {
                    $h['alerts'][$class] = $now;
                    $h['incident']['emailed'] = true;
                    $event = array('type' => 'alert', 'engine' => $e, 'class' => $class, 'code' => $c['code'] ?? null,
                        'http' => $c['http'] ?? null, 'msg' => (string) ($c['message'] ?? ''), 'hint' => (string) ($c['hint'] ?? ''),
                        'since' => (int) $h['incident']['since'], 'count' => (int) $h['streak'], 'now' => $now);
                }
            }
        }
        $health[$e] = $h;
        return array($stats, $health, $event);
    }

    /**
     * Totals per engine for today, yesterday and the last 7 days (today included).
     */
    static function summarize(array $stats, $now, $offset = 0) {
        $zero = array('calls' => 0, 'ok' => 0, 'fail' => 0, 'items' => 0, 'items_ok' => 0, 'chars' => 0, 'chars_ok' => 0);
        $out = array('today' => array(), 'yesterday' => array(), 'last7' => array(), 'days' => array());
        for ($i = 0; $i < 7; $i++) {
            $day = gmdate('Y-m-d', $now + $offset - $i * 86400);
            foreach (($stats[$day] ?? array()) as $e => $d) {
                $row = array_intersect_key($d, $zero) + $zero;
                $out['days'][$day][$e] = $row;
                foreach ($zero as $k => $_) {
                    $out['last7'][$e][$k] = ($out['last7'][$e][$k] ?? 0) + $row[$k];
                    if ($i === 0) {
                        $out['today'][$e][$k] = $row[$k];
                    } elseif ($i === 1) {
                        $out['yesterday'][$e][$k] = $row[$k];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @return array array(subject, body) of the e-mail for an alert or recovery event
     */
    static function compose_mail(array $event, $site, $url) {
        $names = self::engine_names();
        $engine = $names[$event['engine']] ?? $event['engine'];
        $fmt = function ($ts) {
            return gmdate('Y-m-d H:i', (int) $ts) . ' UTC';
        };
        if ($event['type'] === 'recovered') {
            $subject = "[Transposh] $engine works again on $site";
            $body = "Transposh on $site can use $engine again.\n\n"
                . 'The problem started: ' . $fmt($event['since']) . "\nIt ended: " . $fmt($event['now']) . "\n";
            return array($subject, $body);
        }
        $what = !empty($event['code']) ? 'error ' . $event['code'] : (!empty($event['http']) ? 'HTTP ' . $event['http'] : $event['class'] . ' problem');
        $subject = "[Transposh] $engine: $what on $site";
        $lines = array("Transposh on $site cannot use $engine.", '',
            'Problem: ' . $what . ' (' . $event['class'] . ')');
        if (!empty($event['msg'])) {
            $lines[] = 'Message from the engine: ' . $event['msg'];
        }
        $lines[] = 'First failure: ' . $fmt($event['since']);
        $lines[] = 'Failures in a row: ' . (int) $event['count'];
        $lines[] = '';
        $lines[] = 'What it means: ' . $event['hint'];
        $lines[] = '';
        $lines[] = 'Where to check:';
        if ($event['engine'] === 'b') {
            $lines[] = ' - Azure: the Translator resource used by this site (Keys and Endpoint, region, pricing tier, Metrics).';
        }
        $lines[] = ' - WordPress: Transposh > Translation Engines > Engine health > "Test now".';
        $lines[] = '   ' . $url;
        $lines[] = '';
        $lines[] = 'Translations already stored keep working. New phrases stay untranslated while the engine fails.';
        $lines[] = 'You get at most one e-mail per day for this problem, and one when it works again.';
        return array($subject, implode("\n", $lines) . "\n");
    }

    /**
     * Configuration problems worth showing.
     * @param array $cfg autotranslate, bing_first, bing_key, endpoint, region, cron_disabled, last_test, cf_status, mail_to, now
     * @return array list of array(level error|warn|info, text)
     */
    static function evaluate_config(array $cfg) {
        $issues = array();
        $now = (int) ($cfg['now'] ?? 0);
        if (!empty($cfg['autotranslate']) && !empty($cfg['bing_first']) && empty($cfg['bing_key'])) {
            $issues[] = array('error', 'Microsoft Translator is the first engine but no API key is set: every automatic translation falls through.');
        }
        if (!empty($cfg['endpoint']) && stripos($cfg['endpoint'], 'https://') !== 0) {
            $issues[] = array('warn', 'The Microsoft endpoint must start with https://. Otherwise the default endpoint is used.');
        }
        if (!empty($cfg['bing_key']) && trim((string) ($cfg['region'] ?? '')) === '') {
            $issues[] = array('info', 'The region is empty. It is required when the Azure resource is regional (not "global").');
        }
        if (!empty($cfg['cron_disabled'])) {
            $issues[] = array('warn', 'WP-Cron is disabled (DISABLE_WP_CRON): the daily engine test only runs if a system cron calls wp-cron.php.');
        }
        if (!empty($cfg['bing_key'])) {
            if (empty($cfg['last_test'])) {
                $issues[] = array('info', 'The daily engine test has not run yet. Use "Test now".');
            } elseif ($now - (int) $cfg['last_test'] > 36 * 3600) {
                $issues[] = array('warn', 'The daily engine test did not run for ' . (int) floor(($now - (int) $cfg['last_test']) / 3600) . ' hours.');
            }
        }
        if (!empty($cfg['cf_status']) && $cfg['cf_status'] !== 'accepted') {
            $issues[] = array('warn', 'The last Cloudflare cache purge was not accepted (' . $cfg['cf_status'] . ').');
        }
        if (empty($cfg['mail_to'])) {
            $issues[] = array('info', 'No e-mail address is set for Transposh notifications: alerts go to the site administrator address.');
        }
        return $issues;
    }

    /* ------------------------------------------------------------------ recording */

    private function load($name) {
        $v = get_option($name, array());
        return is_array($v) ? $v : array();
    }

    private function alert_engines() {
        return (array) apply_filters('transposh_engine_alert_engines', array('b'));
    }

    private function bing_key_present() {
        $key = defined('TRANSPOSH_MSN_KEY') ? TRANSPOSH_MSN_KEY : $this->transposh->options->msn_key;
        return trim((string) $key) !== '';
    }

    /**
     * Count one engine call and, if needed, send an alert. Never throws: monitoring must not break translation.
     * @param array $c see apply_call()
     */
    function record(array $c) {
        if (!self::enabled()) {
            return;
        }
        try {
            $stats = $this->load(self::OPT_STATS);
            $health = $this->load(self::OPT_HEALTH);
            list($stats, $health, $event) = self::apply_call($stats, $health, $c, self::now(), self::offset(), $this->alert_engines());
            update_option(self::OPT_STATS, $stats, false);
            update_option(self::OPT_HEALTH, $health, false);
            if ($event) {
                $this->notify($event);
            }
        } catch (Throwable $t) {
            if (function_exists('tp_logger')) {
                tp_logger('engine monitor: ' . $t->getMessage(), 1);
            }
        }
    }

    /**
     * Details of a failed call from the HTTP helper of the engines.
     * @return array class, hint, http, code, message
     */
    private function failure_details($engine, $info) {
        $configured = ($engine !== 'b') || $this->bing_key_present();
        $http = is_array($info) ? (int) ($info['code'] ?? 0) : 0;
        $curl = is_array($info) && !empty($info['curl']);
        list($code, $msg) = self::parse_error(is_array($info) ? ($info['body'] ?? '') : '');
        if ($msg === '' && $curl) {
            $msg = (string) $info['curl'];
        }
        if ($msg === '' && !$configured) {
            $msg = 'No API key configured.';
        }
        if ($msg === '' && !$http && !$curl) {
            $msg = 'The engine returned no usable answer.';
        }
        list($class, $hint) = self::classify($engine, $http, $code, $curl, $configured);
        return array('class' => $class, 'hint' => $hint, 'http' => $http ?: null, 'code' => $code, 'message' => $msg);
    }

    /**
     * Called by the on-page translation proxy once the engine answered.
     * @param string $engine one letter engine key
     * @param string $tl target language
     * @param string|array $q url-encoded phrase(s) sent
     * @param mixed $result engine result: array|string or false
     * @param boolean $suggest the editor's one-phrase suggestion
     */
    function record_proxy_call($engine, $tl, $q, $result, $suggest = false, $source = null) {
        if (!self::enabled()) {
            return;
        }
        try {
            $texts = is_array($q) ? $q : ($q === '' ? array() : array($q));
            $chars = 0;
            foreach ($texts as $t) {
                $t = urldecode((string) $t);
                $chars += function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
            }
            $ok = $result !== false && $result !== null && !(is_array($result) && is_array($q) && count($result) !== count($q));
            $c = array('engine' => (string) $engine, 'ok' => $ok, 'items' => count($texts), 'chars' => $chars, 'lang' => (string) $tl,
                'source' => $source ? (string) $source : ($suggest ? 'suggest' : (current_user_can('manage_options') ? 'admin' : 'visitor')));
            if (!$ok) {
                $c += $this->failure_details($c['engine'], class_exists('transposh_translate') ? transposh_translate::get_last_http() : null);
            }
            $this->record($c);
        } catch (Throwable $t) {
            if (function_exists('tp_logger')) {
                tp_logger('engine monitor: ' . $t->getMessage(), 1);
            }
        }
    }

    /**
     * Server-side call made by the REST fill (translate-missing); $encoded_texts are url-encoded like the proxy's.
     */
    function record_rest_call($engine, $lang, array $encoded_texts, $result) {
        $this->record_proxy_call($engine, $lang, array_values($encoded_texts), $result, false, 'rest');
    }

    /* ------------------------------------------------------------------ scheduled test */

    function schedule() {
        if (!wp_next_scheduled(self::CRON_EVENT)) {
            wp_schedule_event(self::now() + 600, 'daily', self::CRON_EVENT);
        }
    }

    function run_daily_check() {
        $this->run_test('b');
    }

    /**
     * One real, tiny translation (5 characters) through the same code path as the pages.
     * @return array ok, class, hint, http, code, message, ms
     */
    function run_test($engine = 'b') {
        if ($engine !== 'b') {
            return array('ok' => false, 'class' => 'unknown', 'message' => 'Only Microsoft Translator can be tested.');
        }
        if (!$this->bing_key_present()) {
            list($class, $hint) = self::classify('b', 0, null, false, false);
            return array('ok' => false, 'class' => $class, 'hint' => $hint, 'message' => 'No API key configured.');
        }
        $t0 = microtime(true);
        $res = transposh_translate::get_bing_translation('fr', 'en', array(urlencode('Hello')));
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $ok = is_array($res) && count($res) === 1 && is_string($res[0]) && trim($res[0]) !== '';
        $c = array('engine' => 'b', 'ok' => $ok, 'items' => 1, 'chars' => 5, 'lang' => 'fr', 'source' => 'health');
        $out = array('ok' => $ok, 'ms' => $ms);
        if (!$ok) {
            $d = $this->failure_details('b', transposh_translate::get_last_http());
            $c += $d;
            $out += $d;
        }
        $this->record($c);
        $health = $this->load(self::OPT_HEALTH);
        $health['_test'] = array('t' => self::now(), 'ok' => $ok, 'ms' => $ms);
        update_option(self::OPT_HEALTH, $health, false);
        return $out;
    }

    /* ------------------------------------------------------------------ e-mail */

    function mail_to() {
        $o = $this->transposh->options;
        return $o->mail_to ? $o->mail_to : get_site_option('admin_email');
    }

    private function send($subject, $body) {
        $to = $this->mail_to();
        if (self::$mailer) {
            return (bool) call_user_func(self::$mailer, $to, $subject, $body);
        }
        return (bool) wp_mail($to, wp_specialchars_decode($subject), $body, array('Content-Type: text/plain; charset=UTF-8'));
    }

    private function notify(array $event) {
        if ($event['type'] === 'recovered' && empty($event['emailed'])) {
            return; // no alert was sent for this incident, so no "recovered" message either
        }
        list($subject, $body) = self::compose_mail($event, wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url(),
            admin_url('admin.php?page=tp_engines'));
        $ok = $this->send($subject, $body);
        $health = $this->load(self::OPT_HEALTH);
        $health['_mail'] = array('t' => self::now(), 'ok' => $ok, 'type' => $event['type'], 'to' => $this->mail_to());
        update_option(self::OPT_HEALTH, $health, false);
    }

    /* ------------------------------------------------------------------ status, REST, admin */

    function config_issues() {
        $o = $this->transposh->options;
        $preferred = array_keys($o->get_sorted_engines());
        $health = $this->load(self::OPT_HEALTH);
        $cf = get_option('transposh_cloudflare_last_purge', null);
        return self::evaluate_config(array(
            'autotranslate' => (bool) $o->enable_autotranslate,
            'bing_first' => isset($preferred[0]) && $preferred[0] === 'b',
            'bing_key' => $this->bing_key_present(),
            'endpoint' => trim((string) $o->msn_endpoint),
            'region' => (string) $o->msn_region,
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'last_test' => $health['_test']['t'] ?? null,
            'cf_status' => is_array($cf) ? ($cf['status'] ?? null) : null,
            'mail_to' => (string) $o->mail_to,
            'now' => self::now(),
        ));
    }

    function status_payload() {
        $health = $this->load(self::OPT_HEALTH);
        $stats = $this->load(self::OPT_STATS);
        return array(
            'enabled' => self::enabled(),
            'version' => defined('TRANSPOSH_PLUGIN_VER') ? TRANSPOSH_PLUGIN_VER : null,
            'now' => self::now(),
            'recipient' => $this->mail_to(),
            'next_test' => wp_next_scheduled(self::CRON_EVENT) ?: null,
            'summary' => self::summarize($stats, self::now(), self::offset()),
            'health' => $health,
            'config_issues' => $this->config_issues(),
        );
    }

    function register_rest_routes() {
        register_rest_route('transposh/v1', '/engine-status', array(
            array('methods' => 'GET', 'permission_callback' => function () {
                return current_user_can('manage_options');
            }, 'callback' => function () {
                return rest_ensure_response($this->status_payload());
            }),
            array('methods' => 'POST', 'permission_callback' => function () {
                return current_user_can('manage_options');
            }, 'callback' => function ($request) {
                return rest_ensure_response($this->do_action_named((string) $request->get_param('action')));
            }),
        ));
    }

    private function do_action_named($action) {
        if ($action === 'test') {
            return $this->run_test('b');
        }
        if ($action === 'mail') {
            $site = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
            $ok = $this->send("[Transposh] Test e-mail from $site",
                "This is a test message from Transposh on $site.\nIf you read it, engine alerts will reach this address.\n");
            return array('ok' => $ok, 'to' => $this->mail_to());
        }
        return array('ok' => false, 'message' => 'Unknown action.');
    }

    function on_ajax_health() {
        check_ajax_referer('tp_engine_health');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not allowed to do this.'), 403);
        }
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $r = $this->do_action_named($do);
        if ($do === 'test') {
            $msg = !empty($r['ok']) ? ('Microsoft Translator answered in ' . (int) $r['ms'] . ' ms.')
                : ('Microsoft Translator failed: ' . (!empty($r['code']) ? 'error ' . $r['code'] . '. ' : '') . ($r['message'] ?? '') . ' ' . ($r['hint'] ?? ''));
        } else {
            $msg = !empty($r['ok']) ? ('Test e-mail sent to ' . $r['to'] . '.') : ('The e-mail could not be sent to ' . ($r['to'] ?? '') . '. Check the mail setup of this site.');
        }
        wp_send_json_success(array('ok' => !empty($r['ok']), 'message' => trim($msg)));
    }

    function tp_notices_engine() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? (string) $screen->id : '';
        if ($id !== 'dashboard' && $id !== 'plugins' && strpos($id, 'tp_') === false && strpos($id, 'transposh') === false) {
            return;
        }
        $names = self::engine_names();
        foreach ($this->load(self::OPT_HEALTH) as $e => $h) {
            if ($e[0] === '_' || empty($h['incident'])) {
                continue;
            }
            $i = $h['incident'];
            $what = !empty($i['code']) ? 'error ' . (int) $i['code'] : (!empty($i['http']) ? 'HTTP ' . (int) $i['http'] : $i['class']);
            echo '<div class="notice notice-error"><p><strong>Transposh:</strong> ' . esc_html($names[$e] ?? $e)
                . ' is failing (' . esc_html($what) . ') since ' . esc_html(wp_date('Y-m-d H:i', (int) $i['since']))
                . '. New phrases are not translated. <a href="' . esc_url(admin_url('admin.php?page=tp_engines')) . '">Engine health</a></p></div>';
        }
    }

    /** "Engine health" section of the Translation Engines tab */
    function render_panel() {
        if (!self::enabled()) {
            return;
        }
        $names = self::engine_names();
        $health = $this->load(self::OPT_HEALTH);
        $sum = self::summarize($this->load(self::OPT_STATS), self::now(), self::offset());
        $engines = array('b' => true);
        foreach (array_keys($health) as $e) {
            if ($e[0] !== '_') {
                $engines[$e] = true;
            }
        }
        foreach (array('today', 'last7') as $k) {
            foreach (array_keys($sum[$k]) as $e) {
                $engines[$e] = true;
            }
        }
        $ts = function ($t) {
            return $t ? esc_html(wp_date('Y-m-d H:i', (int) $t)) : '&ndash;';
        };
        $ratio = function ($row) {
            return $row ? (int) $row['items_ok'] . ' / ' . (int) ($row['items'] - $row['items_ok']) : '0 / 0';
        };
        echo '<div class="postbox"><h2 class="transposh_section_top">Engine health</h2><div class="inside">';
        echo '<table class="widefat striped" style="max-width:62em"><thead><tr><th>Engine</th><th>Status</th><th>Last success</th>'
            . '<th>Last error</th><th>Today: translated / failed</th><th>7 days: translated / failed</th></tr></thead><tbody>';
        foreach (array_keys($engines) as $e) {
            $h = $health[$e] ?? array();
            if (!empty($h['incident'])) {
                $status = '<span style="color:#b32d2e;font-weight:600">Failing</span> since ' . $ts($h['incident']['since']);
            } elseif (!empty($h['last_ok'])) {
                $status = '<span style="color:#1a7f37;font-weight:600">OK</span>';
            } else {
                $status = '<span style="color:#646970">No data yet</span>';
            }
            $err = '&ndash;';
            if (!empty($h['last_error'])) {
                $le = $h['last_error'];
                $err = $ts($le['t']) . ' ' . esc_html((!empty($le['code']) ? 'error ' . $le['code'] : (!empty($le['http']) ? 'HTTP ' . $le['http'] : $le['class'])));
            }
            echo '<tr><td>' . esc_html($names[$e] ?? $e) . '</td><td>' . $status . '</td><td>' . $ts($h['last_ok'] ?? 0)
                . '</td><td>' . $err . '</td><td>' . esc_html($ratio($sum['today'][$e] ?? null))
                . '</td><td>' . esc_html($ratio($sum['last7'][$e] ?? null)) . '</td></tr>';
        }
        echo '</tbody></table>';
        $issues = $this->config_issues();
        if ($issues) {
            echo '<ul style="margin:12px 0">';
            $color = array('error' => '#b32d2e', 'warn' => '#996800', 'info' => '#646970');
            foreach ($issues as $i) {
                echo '<li style="color:' . esc_attr($color[$i[0]]) . '">' . esc_html($i[1]) . '</li>';
            }
            echo '</ul>';
        }
        echo '<p><button type="button" class="button" data-tp-engine-action="test">Test Microsoft Translator now</button> '
            . '<button type="button" class="button" data-tp-engine-action="mail">Send a test e-mail</button></p>';
        echo '<p id="tp_engine_health_result" aria-live="polite"></p>';
        $next = wp_next_scheduled(self::CRON_EVENT);
        echo '<p>Last automatic test: ' . $ts($health['_test']['t'] ?? 0) . ' &middot; next: '
            . ($next ? $ts($next) : '<span style="color:#996800">not scheduled</span>') . '</p>';
        echo '<p class="description">After ' . (int) self::THRESHOLD . ' failures in a row, or a failed daily test, an e-mail goes to <code>'
            . esc_html($this->mail_to()) . '</code>: at most one per engine and problem type every 24 hours, and one when it works again. '
            . 'The counters hold no text, key or visitor data.</p>';
        echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-tp-engine-action]");if(!b)return;'
            . 'var o=document.getElementById("tp_engine_health_result"),d=new URLSearchParams();d.append("action","tp_engine_health");'
            . 'd.append("_ajax_nonce","' . esc_js(wp_create_nonce('tp_engine_health')) . '");d.append("do",b.getAttribute("data-tp-engine-action"));'
            . 'b.disabled=true;o.textContent="...";o.style.color="";'
            . 'fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:d.toString()})'
            . '.then(function(r){return r.json();}).then(function(p){var ok=p&&p.success&&p.data&&p.data.ok;o.style.color=ok?"#1a7f37":"#b32d2e";'
            . 'o.textContent=(p&&p.data&&p.data.message)?p.data.message:"No answer.";})'
            . '.catch(function(){o.style.color="#b32d2e";o.textContent="The request failed.";}).finally(function(){b.disabled=false;});});</script>';
        echo '</div></div>';
    }
}
