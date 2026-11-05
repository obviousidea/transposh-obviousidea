<?php
/** LiteSpeed Cache adapter. Requires a cache-engine integration test before release. */
class transposh_litespeed {
    private $transposh;

    function __construct($transposh) {
        $this->transposh = $transposh;
        add_action('init', array($this, 'protect_editor'), 20);
        add_action('wp', array($this, 'protect_editor'), 20);
        add_action('litespeed_control_finalize', array($this, 'protect_editor'), PHP_INT_MAX);
        add_action('litespeed_tag_finalize', array($this, 'tag_page'));
        add_action('transposh_translations_changed', array($this, 'translations_changed'));
        add_filter('litespeed_optm_js_defer_exc', array($this, 'exclude_scripts'));
        add_filter('litespeed_optm_gm_js_exc', array($this, 'exclude_scripts'));
        add_filter('litespeed_optimize_js_excludes', array($this, 'exclude_scripts'));
    }

    function protect_editor() {
        $param = defined('EDIT_PARAM') ? EDIT_PARAM : 'tpedit';
        // Protect the requested editor even before Transposh resolves permissions.
        $requested = isset($_GET[$param]) && !empty($_GET[$param]);
        if (!$requested && empty($this->transposh->edit_mode)) { return; }
        do_action('litespeed_control_set_nocache', 'Transposh editor');
        if (has_action('litespeed_control_set_nocache') && !defined('LITESPEED_NO_PAGEOPTM')) {
            define('LITESPEED_NO_PAGEOPTM', true);
        }
    }

    function tag_page() {
        $tags = array('transposh');
        $language = $this->transposh->target_language;
        if (is_string($language) && preg_match('/^[a-zA-Z-]{2,12}$/D', $language)) {
            $tags[] = 'transposh_lang_' . $language;
        }
        // LSCache supplies its installation/blog prefix to these custom tags.
        do_action('litespeed_tag_add', $tags);
    }

    function translations_changed($languages) {
        $tags = array();
        if (!is_array($languages) || !$languages) {
            $tags[] = 'transposh';
        } else {
            foreach ($languages as $language) {
                if (!is_string($language) || !preg_match('/^[a-zA-Z-]{2,12}$/D', $language)
                    || !$this->transposh->options->is_active_language($language)) {
                    $tags = array('transposh');
                    break;
                }
                $tags[] = 'transposh_lang_' . $language;
            }
        }
        // Queue after each real dictionary write, not Rocket's late shutdown.
        // LiteSpeed merges tags; its API also queues late/CLI purges for later delivery.
        // Never use litespeed_purge_all: that also clears object cache and OPcache.
        do_action('litespeed_purge', array_values(array_unique($tags)));
    }

    function exclude_scripts($excludes) {
        // Keep translation startup and its localized configuration in original order.
        return array_values(array_unique(array_merge((array) $excludes, array(
            '/transposh', 't_jp', 'jquery.js', 'jquery.min.js'
        ))));
    }
}
