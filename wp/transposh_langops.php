<?php

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Language operations REST API for the ObviousIdea fork.
 *
 * Programmatic language management (enable/disable, per-language engines,
 * dictionary stats) without driving the tp_langs admin form. Built for
 * automation (Hermes agent pipelines): the admin form encodes language
 * state as "code,viewable" comma strings which is fragile to script.
 *
 * Routes (all require manage_options):
 *   GET  /wp-json/transposh/v1/languages
 *   POST /wp-json/transposh/v1/languages/<code>   {active: bool, engines?: ["b","r"]}
 *   GET  /wp-json/transposh/v1/languages/<code>/stats
 *
 * @package transposh
 */
class transposh_langops {

    /** @var transposh */
    private $transposh;

    function __construct(&$transposh) {
        $this->transposh = $transposh;
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    function register_rest_routes() {
        $admin = function () {
            return current_user_can('manage_options');
        };
        register_rest_route('transposh/v1', '/languages', array(
            'methods' => 'GET',
            'permission_callback' => $admin,
            'callback' => array($this, 'list_languages'),
        ));
        register_rest_route('transposh/v1', '/languages/(?P<lang>[a-zA-Z-]{2,5})', array(
            'methods' => 'POST',
            'permission_callback' => $admin,
            'callback' => array($this, 'update_language'),
        ));
        register_rest_route('transposh/v1', '/languages/(?P<lang>[a-zA-Z-]{2,5})/stats', array(
            'methods' => 'GET',
            'permission_callback' => $admin,
            'callback' => array($this, 'language_stats'),
        ));
    }

    private function lang_entry($code, $counts = null) {
        $options = $this->transposh->options;
        $sorted = array_values(array_filter(array_map('trim', explode(',', (string)$options->sorted_languages))));
        $entry = array(
            'code' => $code,
            'name' => transposh_consts::get_language_name($code),
            'orig' => transposh_consts::get_language_orig_name($code),
            'flag' => transposh_consts::get_language_flag($code),
            'active' => $this->transposh->options->is_active_language($code),
            'default' => $this->transposh->options->is_default_language($code),
            'position' => array_search($code, $sorted),
            'engines' => array_values(array_filter(
                array_keys(transposh_consts::get_engines()),
                function ($engine) use ($code) {
                    return transposh_consts::is_supported_engine($code, $engine);
                }
            )),
        );
        if (is_array($counts)) {
            $entry['dictionary_rows'] = isset($counts[$code]) ? (int)$counts[$code] : 0;
        }
        return $entry;
    }

    private function dictionary_counts() {
        global $wpdb;
        $table = $wpdb->prefix . TRANSLATIONS_TABLE;
        $rows = $wpdb->get_results("SELECT lang, COUNT(*) AS n FROM {$table} GROUP BY lang", ARRAY_A);
        $counts = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $counts[$row['lang']] = (int)$row['n'];
            }
        }
        return $counts;
    }

    function list_languages() {
        $counts = $this->dictionary_counts();
        $out = array();
        foreach (transposh_consts::get_langauge_keys() as $code) {
            $out[] = $this->lang_entry($code, $counts);
        }
        return rest_ensure_response(array(
            'default_language' => $this->transposh->options->default_language,
            'preferred_engines' => array_values(array_filter(explode(',', (string)$this->transposh->options->preferred_translators))),
            'languages' => $out,
        ));
    }

    function update_language($request) {
        $code = strtolower(sanitize_text_field((string)$request->get_param('lang')));
        if (!transposh_consts::is_supported_language($code)) {
            return new WP_Error('transposh_invalid_language', __('Unknown language code', TRANSPOSH_TEXT_DOMAIN), array('status' => 404));
        }
        $options = $this->transposh->options;
        if ($options->is_default_language($code)) {
            return new WP_Error('transposh_default_language', __('The default language is always active', TRANSPOSH_TEXT_DOMAIN), array('status' => 400));
        }

        $active = $request->get_param('active');
        if ($active === null && $request->get_param('engines') === null) {
            return new WP_Error('transposh_no_fields', __('Provide "active" and/or "engines"', TRANSPOSH_TEXT_DOMAIN), array('status' => 400));
        }

        $viewable = array_values(array_filter(array_map('trim', explode(',', (string)$options->viewable_languages))));
        $sorted = array_values(array_filter(array_map('trim', explode(',', (string)$options->sorted_languages))));
        $changed = false;

        if ($active !== null) {
            $active = rest_sanitize_boolean($active);
            $pos = array_search($code, $viewable);
            if ($active && $pos === false) {
                $viewable[] = $code;
                if (!in_array($code, $sorted, true)) {
                    $sorted[] = $code;
                }
                $changed = true;
            } elseif (!$active && $pos !== false) {
                unset($viewable[$pos]);
                $viewable = array_values($viewable);
                $changed = true;
            }
        }

        $engines_param = $request->get_param('engines');
        if (is_array($engines_param)) {
            $overrides = get_option(TRANSPOSH_OPTIONS_LANGUAGE_OVERRIDES, array());
            if (!is_array($overrides)) {
                $overrides = array();
            }
            if (!isset($overrides[$code]) || !is_array($overrides[$code])) {
                $overrides[$code] = array('engines' => array());
            }
            if (!isset($overrides[$code]['engines']) || !is_array($overrides[$code]['engines'])) {
                $overrides[$code]['engines'] = array();
            }
            $valid = array_keys(transposh_consts::get_engines());
            foreach ($valid as $engine) {
                $want = in_array($engine, $engines_param, true);
                if ($want) {
                    $overrides[$code]['engines'][$engine] = array('enabled' => 1, 'code' => 'y');
                    $changed = true;
                } elseif (isset($overrides[$code]['engines'][$engine])) {
                    unset($overrides[$code]['engines'][$engine]);
                    $changed = true;
                }
            }
            update_option(TRANSPOSH_OPTIONS_LANGUAGE_OVERRIDES, $overrides, false);
        }

        if ($changed) {
            $options->viewable_languages = implode(',', $viewable);
            $options->sorted_languages = implode(',', $sorted);
            if (is_object($GLOBALS['wp_rewrite'])) {
                $GLOBALS['wp_rewrite']->flush_rules();
            }
            if (isset($this->transposh->database)) {
                $this->transposh->database->cache_clean();
            }
            do_action('transposh_language_updated', $code);
        }

        return rest_ensure_response(array(
            'changed' => $changed,
            'language' => $this->lang_entry($code, $this->dictionary_counts()),
        ));
    }

    function language_stats($request) {
        global $wpdb;
        $code = strtolower(sanitize_text_field((string)$request->get_param('lang')));
        if (!transposh_consts::is_supported_language($code)) {
            return new WP_Error('transposh_invalid_language', __('Unknown language code', TRANSPOSH_TEXT_DOMAIN), array('status' => 404));
        }
        $table = $wpdb->prefix . TRANSLATIONS_TABLE;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS rows_total, MAX(timestamp) AS last_update FROM {$table} WHERE lang = %s", $code
        ), ARRAY_A);
        $by_source = $wpdb->get_results($wpdb->prepare(
            "SELECT source, COUNT(*) AS n FROM {$table} WHERE lang = %s GROUP BY source", $code
        ), ARRAY_A);
        $source_engine_map = array(1 => 'g', 2 => 'b', 3 => 'a', 4 => 'y', 5 => 'u', 6 => 'l', 7 => 'o', 8 => 'r');
        $sources = array();
        if (is_array($by_source)) {
            foreach ($by_source as $r) {
                $key = $source_engine_map[(int)$r['source']] ?? (string)$r['source'];
                $sources[$key] = ($sources[$key] ?? 0) + (int)$r['n'];
            }
        }
        return rest_ensure_response(array(
            'language' => $this->lang_entry($code),
            'dictionary_rows' => $row ? (int)$row['rows_total'] : 0,
            'last_update' => $row ? $row['last_update'] : null,
            'by_engine_source' => $sources,
        ));
    }

}
