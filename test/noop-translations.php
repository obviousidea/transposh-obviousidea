<?php
/** Isolated write-path tests: SQL and translation actions are replaced by spies. */
class transposh_noop_fixture extends transposh_database {
    public $fixture_old;
    public $fixture_deletes = 0;
    public $fixture_seo_status = false; // stable: pre/post snapshots match, no flip events
    public $fixture_flip_on_cache_delete = false; // simulate: first row for the language appears
    public $fixture_flip_on_delete = false; // simulate: last row for the language disappears
    function fetch_translation($original, $lang) { return $this->fixture_old; }
    function cache_delete($original, $lang) {
        $this->fixture_deletes++;
        if ($this->fixture_flip_on_cache_delete) {
            $this->fixture_seo_status = true;
        }
    }
    function language_has_translations($lang) { return $this->fixture_seo_status; }
}
function transposh_test_noop_translations($fixture_class = 'transposh_noop_fixture') {
    $real_db = $GLOBALS['wpdb']; $real_post = $_POST;
    $hook_names = array('transposh_translations_changed','transposh_human_translation','transposh_language_seo_status_changed');
    $saved_hooks = array();
    foreach ($hook_names as $name) {
        $saved_hooks[$name] = $GLOBALS['wp_filter'][$name] ?? null;
        unset($GLOBALS['wp_filter'][$name]);
    }
    $events = array();
    $seo_events = array();
    add_action('transposh_translations_changed', static function($langs) use (&$events) { $events[] = $langs; });
    add_action('transposh_language_seo_status_changed', static function($lang) use (&$seo_events) { $seo_events[] = $lang; });
    $spy = new class($real_db) {
        public $queries = array(); public $last_error = ''; public $num_queries = 0;
        public $prefix = 'unit_'; public $options = 'unit_options'; public $insert_id = 0;
        public $log_rows = array(); public $main_rows = array(); public $on_delete = null;
        private $real;
        function __construct($real) { $this->real = $real; }
        function _escape($value) { return $this->real->_escape($value); }
        function esc_like($value) { return addcslashes((string) $value, '_%\\'); }
        function prepare($query, ...$args) { return vsprintf($query, array_map(array($this, '_escape'), $args)); }
        function query($sql) {
            $this->queries[] = $sql; $this->num_queries++;
            if ($this->on_delete !== null && preg_match('/^\s*(DELETE|DELETE\s)/i', $sql)) {
                call_user_func($this->on_delete);
            }
            return 1;
        }
        function insert($table, $data, $formats = null) { $this->num_queries++; return 1; }
        function delete($table, $where, $where_formats = null) { $this->num_queries++; return 1; }
        // WP 6.7 core option API (delete_transient) reaches these while $GLOBALS['wpdb'] is the spy
        function get_row($query = null, $output = null, $y = 0) { return null; }
        function get_var($query = null, $y = 0) { return null; }
        function get_col($query = null) { return array(); }
        function get_results($query = null, $output = null) {
            // the log SELECT must be able to come back empty while the main table has rows
            return (strpos((string) $query, 'unit_translation_log') !== false) ? $this->log_rows : $this->main_rows;
        }
    };
    $ref = new ReflectionClass(get_parent_class($fixture_class));
    $db = (new ReflectionClass($fixture_class))->newInstanceWithoutConstructor();
    $options = new class {
        public $enable_autotranslate = true; public $transposh_backup_schedule = 0;
        public $viewable_languages = 'fr';
        function is_active_language($lang) { return in_array($lang, array('fr','de'), true); }
        function is_default_language($lang) { return $lang === 'en'; }
    };
    foreach (array('transposh'=>(object)array('options'=>$options),'translation_table'=>'unit_translations','translation_log_table'=>'unit_translation_log') as $name=>$value) {
        $property = $ref->getProperty($name); $property->setAccessible(true); $property->setValue($db, $value);
    }
    $spy->on_delete = static function () use ($db) {
        if ($db->fixture_flip_on_delete) {
            $db->fixture_seo_status = false; // the DELETE removed the last row of the language
        }
    };
    $checks = array(); $observations = array();
    try {
        $GLOBALS['wpdb'] = $spy;
        $examples = array('plain'=>'Bonjour','ampersand'=>'Photos & images','apostrophe'=>"L’image d'aujourd’hui",'quotes'=>'Say "Bonjour"','entity'=>'A &amp; B','percent'=>'Literal %20 and %2F','markup'=>'<strong>Bonjour</strong>');
        foreach ($examples as $name=>$text) {
            $spy->queries = array(); $events = array(); $db->fixture_deletes = 0;
            $db->fixture_old = array(0, htmlspecialchars($text));
            $_POST = array('items'=>1,'tk0'=>'Unit no-op phrase','tr0'=>rawurlencode($text),'ln0'=>'fr','sr0'=>0);
            $db->update_translation('unit-test');
            $checks['identical_'.$name] = !$spy->queries && !$events && $db->fixture_deletes === 0;
            $observations[$name] = array('sql_writes'=>count($spy->queries),'invalidation_events'=>count($events));
        }
        foreach (array('text_change'=>array(0,'Ancien','Nouveau'),'source_change'=>array(1,'Bonjour','Bonjour')) as $name=>$case) {
            $spy->queries = array(); $events = array(); $db->fixture_deletes = 0;
            $db->fixture_old = array($case[0], htmlspecialchars($case[1]));
            $_POST = array('items'=>1,'tk0'=>'Unit changed phrase','tr0'=>rawurlencode($case[2]),'ln0'=>'fr','sr0'=>0);
            $db->update_translation('unit-test');
            // two cache_delete calls per changed item are intentional: once when the batch
            // item is accepted and once again after the write commits (a concurrent reader may
            // have re-populated the entry from stale data while the batch was running)
            $checks[$name.'_still_invalidates_language'] = count($spy->queries) === 3 && $events === array(array('fr')) && $db->fixture_deletes === 2;
        }
        // ---- SEO status flip scenarios (hreflang/noindex cross-language purge events) ----
        $real_server = $_SERVER;
        try {
            $_SERVER['REMOTE_ADDR'] = '';
            // (a) first human translation for the language: flip must fire exactly once
            $spy->queries = array(); $events = array(); $seo_events = array(); $spy->log_rows = array(); $spy->main_rows = array();
            $db->fixture_old = null; $db->fixture_deletes = 0;
            $db->fixture_seo_status = false; $db->fixture_flip_on_cache_delete = true;
            $_POST = array('items'=>1,'tk0'=>'Unit flip phrase','tr0'=>rawurlencode('Premiere'),'ln0'=>'fr','sr0'=>0);
            $db->update_translation('unit-test');
            $checks['seo_flip_fires_on_first_translation'] = $seo_events === array('fr') && $events === array(array('fr'));
            // (b) deletion of the last translation: pre true / post false must fire the flip
            $spy->queries = array(); $events = array(); $seo_events = array();
            $spy->log_rows = array();
            $spy->main_rows = array((object)array('translated'=>'Premiere','translated_by'=>'','timestamp'=>1,'source'=>'0'));
            $db->fixture_seo_status = true; $db->fixture_flip_on_cache_delete = false; $db->fixture_flip_on_delete = true;
            $db->del_translation_history('Unit flip phrase', 'fr', 1);
            $checks['seo_flip_fires_on_last_deletion'] = $seo_events === array('fr') && $events === array(array('fr'));
            // (c) dedup without duplicates: nothing is notified and no flip fires
            $spy->queries = array(); $events = array(); $seo_events = array(); $spy->log_rows = array(); $spy->main_rows = array();
            $db->fixture_flip_on_delete = false; $db->fixture_seo_status = false;
            $db->deduplicate_auto();
            $checks['dedup_noop_is_silent'] = $events === array() && $seo_events === array() && $spy->queries === array();
            // (d) dedup deleting duplicate rows that empties the language: flip must fire once
            $spy->queries = array(); $events = array(); $seo_events = array();
            $spy->log_rows = array();
            $spy->main_rows = array((object)array('original'=>'dup','translated'=>'dup-fr','lang'=>'fr','count'=>'2'));
            $db->fixture_seo_status = true;
            $db->deduplicate_auto();
            $checks['seo_flip_fires_on_dedup_empties_language'] = $seo_events === array('fr') && $events === array(array('fr'));
        } finally {
            $_SERVER = $real_server; $spy->log_rows = array(); $spy->main_rows = array(); $spy->on_delete = null;
            $db->fixture_flip_on_cache_delete = false; $db->fixture_flip_on_delete = false;
        }
        return array('checks'=>$checks,'observations'=>$observations,'real_translation_writes'=>false);
    } finally {
        $GLOBALS['wpdb'] = $real_db; $_POST = $real_post;
        foreach ($hook_names as $name) {
            unset($GLOBALS['wp_filter'][$name]);
            if ($saved_hooks[$name] !== null) $GLOBALS['wp_filter'][$name] = $saved_hooks[$name];
        }
    }
}
if (defined('WP_CLI') && WP_CLI) {
    $result = transposh_test_noop_translations();
    WP_CLI::line(wp_json_encode($result));
    if (in_array(false, $result['checks'], true)) WP_CLI::error('No-op translation regression failed.');
    WP_CLI::success(count($result['checks']).' no-op translation assertions passed.');
}
