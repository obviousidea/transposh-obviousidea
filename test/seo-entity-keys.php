<?php
/**
 * Isolated CLI regression, no WordPress bootstrap, network or real database.
 * php test/seo-entity-keys.php [--database-file=/path/to/baseline/transposh_db.php]
 */
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    throw new RuntimeException('Run in an isolated PHP CLI process, outside WordPress.');
}
error_reporting(E_ALL & ~E_DEPRECATED);
define('ARRAY_A', 'ARRAY_A');
define('HOUR_IN_SECONDS', 3600);
define('ABSPATH', __DIR__ . '/isolated-no-wordpress/');
$GLOBALS['wp_filter'] = array();
$GLOBALS['entity_events'] = array();
function esc_sql($v) { return addslashes((string) $v); }
function tp_logger($message, $severity = 3, $trace = false) {}
function apply_filters($name, $value, ...$args) { return $value; }
function add_action($name, $callback, ...$args) { $GLOBALS['wp_filter'][$name][] = $callback; }
function do_action($name, ...$args) {
    $GLOBALS['entity_events'][] = array($name, $args);
    foreach ($GLOBALS['wp_filter'][$name] ?? array() as $callback) { $callback(...$args); }
}
function get_transient($key) { return false; }
function set_transient($key, $value, $ttl) { return true; }
function delete_transient($key) { return true; }
function get_option($key, $default = false) { return $default; }
function update_option($key, $value, $autoload = null) { return true; }
function get_current_user_id() { return 1; }
function current_user_can($cap) { return true; }
function is_user_logged_in() { return true; }
function wp_generate_uuid4() { return 'isolated-epoch'; }
function home_url($path = '') { return 'https://unit.invalid' . $path; }
class transposh_translate {
    static function getProtectedTerms() { return array(); }
}
require_once dirname(__DIR__) . '/core/parser.php';
$args = getopt('', array('database-file:'));
require_once $args['database-file'] ?? dirname(__DIR__) . '/wp/transposh_db.php';

class entity_sql_fixture {
    public $prefix = 'unit_', $options = 'unit_options', $last_error = '', $num_queries = 0;
    public $rows = array(), $queries = array(), $fail = false;
    function _escape($v) { return esc_sql($v); }
    function esc_like($v) { return addcslashes($v, '_%\\'); }
    function prepare($query, ...$args) { return vsprintf($query, array_map(array($this, '_escape'), $args)); }
    function get_var($query = '') { return 1; }
    function originals($query) {
        preg_match_all("/original\\s*=\\s*'((?:\\\\.|[^'])*)'/", $query, $m);
        return array_map('stripslashes', $m[1]);
    }
    function lang($query) {
        preg_match("/lang\\s*=\\s*'([^']*)'/", $query, $m);
        return $m[1] ?? 'fr';
    }
    function get_row($query) {
        $this->num_queries++; $this->queries[] = $query;
        $this->last_error = $this->fail ? 'synthetic SQL failure' : '';
        if ($this->fail) return null;
        foreach ($this->originals($query) as $original) {
            if (isset($this->rows[$this->lang($query)][$original])) {
                return (object) $this->rows[$this->lang($query)][$original];
            }
        }
        return null;
    }
    function get_results($query, $output = null) {
        $this->num_queries++; $this->queries[] = $query;
        $this->last_error = $this->fail ? 'synthetic SQL failure' : '';
        if ($this->fail) return array();
        $out = array();
        foreach ($this->originals($query) as $original) {
            if (isset($this->rows[$this->lang($query)][$original])) {
                $row = $this->rows[$this->lang($query)][$original];
                $out[] = $output === ARRAY_A ? $row : (object) $row;
            }
        }
        return $out;
    }
    function query($query) {
        $this->queries[] = $query; $this->num_queries++;
        if (strpos($query, 'DELETE FROM unit_translations WHERE') === 0) {
            foreach ($this->originals($query) as $original) {
                unset($this->rows[$this->lang($query)][$original]);
            }
        }
        if (strpos($query, 'INSERT INTO unit_translations ') === 0 && strpos($query, ' VALUES ') !== false) {
            preg_match_all("/\\('([^']*)','([^']*)','([^']*)','([^']*)','([^']*)'\\)/", $query, $tuples, PREG_SET_ORDER);
            foreach ($tuples as $tuple) {
                $original = stripslashes($tuple[1]);
                $this->rows[$tuple[3]][$original] = array('original'=>$original,'translated'=>stripslashes($tuple[2]),'lang'=>$tuple[3],'source'=>$tuple[5]);
            }
        }
        return 1;
    }
}
class entity_dictionary_fixture extends transposh_database {
    public $cache = array(), $deletes = 0;
    function cache_fetch($original, $lang) { return $this->cache[$lang][$original] ?? false; }
    function cache_store($original, $lang, $value, $ttl) { $this->cache[$lang][$original] = $value; return true; }
    function cache_delete($original, $lang) {
        parent::cache_delete($original, $lang);
        unset($this->cache[$lang][$original]); $this->deletes++;
    }
    function language_has_translations($lang) { return true; }
    function invalidate_seo_status($lang, $was = null) {}
}
function entity_database() {
    $ref = new ReflectionClass('transposh_database');
    $db = (new ReflectionClass('entity_dictionary_fixture'))->newInstanceWithoutConstructor();
    $options = new class {
        public $enable_autotranslate = true, $transposh_backup_schedule = 0, $viewable_languages = 'fr,de,es,it,pt,pl';
        function is_active_language($lang) { return in_array($lang, array('fr','de','es','it','pt','pl'), true); }
        function is_default_language($lang) { return $lang === 'en'; }
    };
    $tp = new class($options) {
        public $options;
        function __construct($options) { $this->options = $options; }
        function is_translator() { return true; }
    };
    foreach (array('transposh'=>$tp,'translation_table'=>'unit_translations','translation_log_table'=>'unit_translation_log','translations'=>array(),'dictionary_namespace'=>'entity-unit','dictionary_epoch'=>'unit') as $name=>$value) {
        $p = $ref->getProperty($name); $p->setAccessible(true); $p->setValue($db, $value);
    }
    return $db;
}
function entity_seed($sql, $original, $lang, $translation, $source = 0) {
    // Match the pre-existing editor's raw-token storage, not the candidate helper.
    $key = htmlspecialchars($original, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    $sql->rows[$lang][$key] = array('original'=>$key,'translated'=>htmlspecialchars($translation, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'),'source'=>$source);
}
$checks = array();
$title = "Official Download page for Obviousidea's Software - no ads";
$forms = array('raw'=>$title,'zero_padded'=>str_replace("'", '&#039;', $title),'decimal'=>str_replace("'", '&#39;', $title),'hex'=>str_replace("'", '&#x27;', $title),'named'=>str_replace("'", '&apos;', $title));
$words = array('fr'=>'TÃ©lÃ©chargements officiels','de'=>'Offizielle Downloads','es'=>'Descargas oficiales','it'=>'Download ufficiali','pt'=>'Downloads oficiais','pl'=>'Oficjalne pobieranie');
foreach ($words as $lang=>$translation) {
    $sql = new entity_sql_fixture(); $GLOBALS['wpdb'] = $sql; $db = entity_database();
    entity_seed($sql, $title, $lang, $translation);
    foreach ($forms as $kind=>$original) {
        $found = $db->fetch_translation($original, $lang);
        $checks["read_{$lang}_{$kind}"] = $found === array(0, $translation);
    }
    $checks['memoized_equivalent_keys_'.$lang] = $sql->num_queries === 1;
    $sql->num_queries = 0; $db = entity_database();
    $db->prefetch_translations(array_fill_keys(array_values($forms), true), $lang);
    foreach ($forms as $kind=>$original) {
        $checks["prefetch_{$lang}_{$kind}"] = $db->fetch_translation($original, $lang) === array(0, $translation);
    }
    $checks['one_prefetch_query_'.$lang] = $sql->num_queries === 1;
    $sql->num_queries = 0; $db = entity_database();
    $db->prefetch_translations(array_values($forms), $lang);
    $checks['prefetch_list_format_'.$lang] = $db->fetch_translation($forms['hex'], $lang) === array(0, $translation) && $sql->num_queries === 1;
}
$sql = new entity_sql_fixture(); $GLOBALS['wpdb'] = $sql;
foreach (array('apostrophe'=>"A ' B",'double_quote'=>'A " B','ampersand'=>'A & B','unicode'=>'Lâ€™image dâ€™aujourdâ€™hui','percent'=>'Literal %20 and %2F','markup'=>'<strong>text</strong>') as $name=>$raw) {
    entity_seed($sql, $raw, 'fr', 'FR '.$name);
    foreach (array('raw'=>$raw,'encoded'=>htmlspecialchars($raw, ENT_QUOTES | ENT_HTML401, 'UTF-8')) as $kind=>$form) {
        $db = entity_database();
        $checks['roundtrip_'.$name.'_'.$kind] = $db->fetch_translation($form, 'fr') === array(0, 'FR '.$name);
    }
}
// Decode once only: literal entity text in HTML is not the same phrase as a quote.
entity_seed($sql, "A ' B", 'fr', 'QUOTE');
entity_seed($sql, 'A &#039; B', 'fr', 'LITERAL ENTITY');
$db = entity_database();
$checks['literal_entity_not_collapsed'] = $db->fetch_translation('A &amp;#039; B','fr') === array(0,'LITERAL ENTITY')
    && $db->fetch_translation('A &#039; B','fr') === array(0,'QUOTE');

// Actual write path with a SQL spy, including no-op and machine-vs-human protection.
$sql = new entity_sql_fixture(); $GLOBALS['wpdb'] = $sql; $db = entity_database();
entity_seed($sql, $title, 'fr', 'Ancien');
$db->prefetch_translations(array($forms['zero_padded']), 'fr');
$_POST = array('items'=>1,'tk0'=>html_entity_decode($forms['zero_padded'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),'ln0'=>'fr','sr0'=>0,'tr0'=>'Nouveau');
$GLOBALS['entity_events'] = array();
$db->update_translation('unit-test');
$canonical = htmlspecialchars($title, ENT_QUOTES | ENT_HTML401, 'UTF-8');
$checks['write_encoded_updates_existing_key'] = count($sql->rows['fr']) === 1 && ($sql->rows['fr'][$canonical]['translated'] ?? null) === 'Nouveau';
$checks['write_clears_prefetch_and_dictionary_cache'] = $db->fetch_translation($title,'fr') === array('0','Nouveau') && $db->deletes === 2;
$checks['write_notifies_language'] = in_array(array('transposh_translations_changed',array(array('fr'))),$GLOBALS['entity_events'],true);
$sql->queries = array(); $GLOBALS['entity_events'] = array(); $deletes = $db->deletes;
$_POST['tk0'] = $title; $db->update_translation('unit-test');
$checks['equivalent_raw_save_is_noop'] = $sql->queries === array() && $GLOBALS['entity_events'] === array() && $db->deletes === $deletes;
$_POST['tk0'] = html_entity_decode($forms['hex'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); $_POST['sr0'] = 2; $_POST['tr0'] = 'Machine';
$db->update_translation('unit-test');
$checks['machine_cannot_replace_human_alias'] = $sql->queries === array() && $db->fetch_translation($title,'fr') === array('0','Nouveau');

// An editor token is browser-decoded plain text. A numeric quote entity here
// is deliberately literal content and must not overwrite the real apostrophe.
$sql = new entity_sql_fixture(); $GLOBALS['wpdb'] = $sql; $db = entity_database();
entity_seed($sql, "A ' B", 'fr', 'QUOTE');
entity_seed($sql, 'A &#039; B', 'fr', 'LITERAL OLD');
$_POST = array('items'=>1,'tk0'=>'A &#039; B','ln0'=>'fr','sr0'=>0,'tr0'=>'LITERAL NEW');
$db->update_translation('unit-test');
$checks['literal_editor_token_stays_distinct_on_write'] = count($sql->rows['fr']) === 2
    && $db->fetch_translation('A &#039; B','fr') === array(0,'QUOTE')
    && $db->fetch_translation('A &amp;#039; B','fr') === array('0','LITERAL NEW');
$sql->queries = array(); $db->update_translation('unit-test');
$checks['literal_editor_identical_save_is_noop'] = $sql->queries === array();

// Duplicate raw/encoded originals in one batch must not create two INSERT tuples.
$sql = new entity_sql_fixture(); $GLOBALS['wpdb'] = $sql; $db = entity_database();
$_POST = array('items'=>2,'tk0'=>$title,'ln0'=>'fr','sr0'=>0,'tr0'=>'PremiÃ¨re','tk1'=>html_entity_decode($forms['decimal'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),'ln1'=>'fr','sr1'=>0,'tr1'=>'Seconde');
$db->update_translation('unit-test');
$checks['batch_deduplicates_equivalent_originals'] = count($sql->rows['fr']) === 1 && ($sql->rows['fr'][$canonical]['translated'] ?? null) === 'PremiÃ¨re';

// Full parser + real database read/prefetch callbacks, including head metadata.
foreach ($words as $lang=>$translation) {
    $sql = new entity_sql_fixture(); $GLOBALS['wpdb'] = $sql; $db = entity_database();
    entity_seed($sql,$title,$lang,$translation);
    $parser = new tp_parser(); $parser->lang = $lang; $parser->is_auto_translate = false; $parser->is_edit_mode = false; $parser->allow_ad = false;
    $parser->change_parsing_rules(false, false, false); // whole phrases observed on the affected live pages
    $parser->fetch_translate_func = array($db,'fetch_translation');
    $parser->prefetch_translate_func = array($db,'prefetch_translations');
    $parser->url_rewrite_func = static function($url) { return $url; };
    $encoded = htmlspecialchars($title,ENT_QUOTES | ENT_HTML401,'UTF-8');
    $html = (string) $parser->fix_html('<html><head><title>'.$encoded.'</title><meta name="description" content="'.$encoded.'"><meta property="og:title" content="'.$encoded.'"><meta name="twitter:title" content="'.$encoded.'"></head><body><p>Unchanged</p></body></html>');
    $checks['render_title_'.$lang] = strpos($html,'<title>'.$translation.'</title>') !== false;
    $checks['render_description_'.$lang] = strpos($html,'name="description" content="'.$translation.'"') !== false;
    $checks['render_og_'.$lang] = strpos($html,'property="og:title" content="'.$translation.'"') !== false;
    $checks['render_twitter_'.$lang] = strpos($html,'name="twitter:title" content="'.$translation.'"') !== false;
    $checks['render_body_unchanged_'.$lang] = strpos($html,'<p>Unchanged</p>') !== false;
}
$sql = new entity_sql_fixture(); $GLOBALS['wpdb'] = $sql; $db = entity_database(); $sql->fail = true;
$checks['sql_errors_not_memoized'] = $db->fetch_translation($title,'fr') === false && $db->fetch_translation($title,'fr') === false && $sql->num_queries === 2;

// Existing isolated fixtures are exercised in the same process as compatibility gates.
$GLOBALS['wpdb'] = new entity_sql_fixture();
$GLOBALS['my_transposh_plugin'] = (object) array('options'=>new class {
    public $viewable_languages = 'fr,de';
    function is_active_language($lang) { return in_array($lang,array('fr','de'),true); }
});
$existing_cache = require __DIR__.'/cache-regression.php';
foreach ($existing_cache as $name=>$passed) { $checks['existing_cache_'.$name] = $passed; }
$existing_parser = require __DIR__.'/parser-fragments.php';
foreach ($existing_parser as $name=>$passed) { $checks['existing_parser_'.$name] = $passed; }
require __DIR__.'/noop-translations.php';
$existing_noop = transposh_test_noop_translations();
foreach ($existing_noop['checks'] as $name=>$passed) { $checks['existing_noop_'.$name] = $passed; }
$failed = array_keys(array_filter($checks, static function($passed) { return !$passed; }));
echo json_encode(array('php'=>PHP_VERSION,'assertions'=>count($checks),'passed'=>count($checks)-count($failed),'failed'=>$failed,'checks'=>$checks,'network_requests'=>0,'real_database_writes'=>0),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed ? 1 : 0);

