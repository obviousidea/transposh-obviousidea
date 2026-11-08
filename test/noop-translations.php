<?php
/** Isolated write-path tests: SQL and translation actions are replaced by spies. */
class transposh_noop_fixture extends transposh_database {
    public $fixture_old;
    public $fixture_deletes = 0;
    public $fixture_seo_status = false; // stable: pre/post snapshots match, no flip events
    function fetch_translation($original, $lang) { return $this->fixture_old; }
    function cache_delete($original, $lang) { $this->fixture_deletes++; }
    function language_has_translations($lang) { return $this->fixture_seo_status; }
}
function transposh_test_noop_translations($fixture_class = 'transposh_noop_fixture') {
    $real_db = $GLOBALS['wpdb']; $real_post = $_POST;
    $hook_names = array('transposh_translations_changed','transposh_human_translation');
    $saved_hooks = array();
    foreach ($hook_names as $name) {
        $saved_hooks[$name] = $GLOBALS['wp_filter'][$name] ?? null;
        unset($GLOBALS['wp_filter'][$name]);
    }
    $events = array();
    add_action('transposh_translations_changed', static function($langs) use (&$events) { $events[] = $langs; });
    $spy = new class($real_db) {
        public $queries = array(); public $last_error = ''; public $num_queries = 0;
        private $real;
        function __construct($real) { $this->real = $real; }
        function _escape($value) { return $this->real->_escape($value); }
        function query($sql) { $this->queries[] = $sql; $this->num_queries++; return 1; }
    };
    $ref = new ReflectionClass(get_parent_class($fixture_class));
    $db = (new ReflectionClass($fixture_class))->newInstanceWithoutConstructor();
    $options = new class {
        public $enable_autotranslate = true; public $transposh_backup_schedule = 0;
        function is_active_language($lang) { return in_array($lang, array('fr','de'), true); }
    };
    foreach (array('transposh'=>(object)array('options'=>$options),'translation_table'=>'unit_translations','translation_log_table'=>'unit_translation_log') as $name=>$value) {
        $property = $ref->getProperty($name); $property->setAccessible(true); $property->setValue($db, $value);
    }
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
            $checks[$name.'_still_invalidates_language'] = count($spy->queries) === 3 && $events === array(array('fr')) && $db->fixture_deletes === 1;
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
