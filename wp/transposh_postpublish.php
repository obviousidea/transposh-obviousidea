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
 * Provides the side widget in the page/edit pages which will do translations
 */

/**
 * class that makes changed to the edit page and post page, adding our change to the side ba
 */
class transposh_postpublish {

    /** @var transposh_plugin Container class */
    private $transposh;

    /** @var boolean Did we just edited/saved? */
    private $just_published = false;

    /**
     *
     * Construct our class
     * @param transposh_plugin $transposh
     */
    function __construct(&$transposh) {
        $this->transposh = &$transposh;
        // we need this anyway because of the change language selection
        add_action('edit_post', array(&$this, 'on_edit'));
        add_action('admin_menu', array(&$this, 'on_admin_menu'));
        add_action('rest_api_init', array(&$this, 'register_rest_routes'));
    }

    /**
     * Phrases that only exist in the rendered default-language <head>: the SEO title,
     * meta description, Open Graph/Twitter texts and JSON-LD headline/description
     * produced by SEO plugins (SEOPress, Yoast...). They are not part of the post
     * content, so "translate all" and post-publish translation never queued them.
     * The page is fetched anonymously so the keys match exactly what the parser
     * meets at render time.
     *
     * Failures are never silent: the status tells why nothing was collected
     * (disabled, not_public, protected, http_error, http_<code>, no_head, ok).
     * @param int $postID
     * @return array array('status' => string, 'phrases' => phrase => phrase)
     */
    function get_rendered_head_phrases($postID) {
        $none = function ($status) use ($postID) {
            if ($status !== 'disabled') {
                tp_logger("head phrases: post $postID not collected ($status)", 2);
            }
            return array('status' => $status, 'phrases' => array());
        };
        if (!apply_filters('transposh_collect_head_phrases', true, $postID)) {
            return $none('disabled');
        }
        $post = get_post($postID);
        if (!$post || $post->post_status !== 'publish' || (function_exists('is_post_publicly_viewable') && !is_post_publicly_viewable($post))) {
            return $none('not_public');
        }
        if (post_password_required($post) || $post->post_password !== '') {
            return $none('protected'); // a 200 would be the password form, not the real head
        }
        $permalink = get_permalink($postID);
        if (!$permalink) {
            return $none('no_permalink');
        }
        // Anonymous request (no cookies, so no remembered-language redirect); the
        // query argument keeps WP Rocket's file cache out of the way (query strings
        // are not cached by default). No JavaScript runs, so beacon-based analytics
        // do not count it. Redirects are not followed: a redirect is reported.
        $response = wp_remote_get(add_query_arg('tp_head_phrases', '1', $permalink), array(
            'timeout' => (int) apply_filters('transposh_head_phrases_timeout', 8),
            'redirection' => 0,
            'user-agent' => 'Transposh head phrase collector; ' . home_url('/'),
            'headers' => array('Cache-Control' => 'no-cache'),
        ));
        if (is_wp_error($response)) {
            return $none('http_error');
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return $none('http_' . $code);
        }
        $phrases = self::extract_head_phrases(wp_remote_retrieve_body($response), $this->parsing_rules());
        if ($phrases === null) {
            return $none('no_head');
        }
        return array('status' => 'ok', 'phrases' => $phrases);
    }

    /**
     * Extract translatable phrases from an HTML document head.
     * Mirrors tp_parser::fix_html(): same NBSP normalisation, same DOM selection
     * of JSON-LD scripts, same tagging rules for <title>/<meta>/<link title>.
     * Pure function (no WordPress calls) so it can be tested in isolation.
     * @param string $html
     * @param array $rules array(break_puncts, break_numbers, break_entities), as applied at render
     * @return array|null phrase => phrase, null when the document has no head
     */
    static function extract_head_phrases($html, $rules = array(true, true, true)) {
        if (!is_string($html) || !preg_match('~<head\b[^>]*>(.*?)</head>~is', $html, $m)) {
            return null;
        }
        // fix_html() replaces UTF-8 non-breaking spaces before building its DOM
        $head = str_replace(chr(0xC2) . chr(0xA0), ' ', $m[1]);
        $phrases = array();
        // JSON-LD: same DOM selector and decoding as fix_html(), whole headline/description values
        $dom = str_get_html_transposh('<html><head>' . $head . '</head><body></body></html>', false);
        if (is_object($dom)) {
            foreach ($dom->find('script[type=application/ld+json]') as $e) {
                $data = json_decode($e->innertext, true);
                if (!is_array($data)) {
                    continue;
                }
                array_walk_recursive($data, function ($value, $key) use (&$phrases) {
                    if (($key === 'headline' || $key === 'description') && is_string($value) && trim($value) !== '') {
                        $phrases[$value] = $value;
                    }
                });
            }
            $dom->clear();
        }
        // <title>, <meta content> and <link title>: reuse the parser's own tagging rules
        $head = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', '', $head);
        $parser = new tp_parser();
        $parser->change_parsing_rules($rules[0], $rules[1], $rules[2]);
        foreach ($parser->get_phrases_list($head) as $phrase) {
            $phrases[$phrase] = $phrase;
        }
        return $phrases;
    }

    /**
     * Phrase-breaking rules used by the page render, see transposh_plugin::process_page()
     * @return array array(break_puncts, break_numbers, break_entities)
     */
    function parsing_rules() {
        $o = $this->transposh->options;
        return array(!$o->parser_dont_break_puncts, !$o->parser_dont_break_numbers, !$o->parser_dont_break_entities);
    }

    /**
     * All phrases of a post, grouped by origin
     * @param int $postID
     * @return array array('title' => filtered title, 'content' => phrase=>phrase,
     *               'head' => phrase=>phrase, 'head_status' => string)
     */
    function collect_post_phrases($postID) {
        global $post; // some filters below expect it
        $post = get_post($postID);
        $title = apply_filters('the_title', $post->post_title);
        $content = apply_filters('the_content', $post->post_content);
        $the_content_feed = apply_filters('the_content_feed', $content);
        $excerpt = apply_filters('get_the_excerpt', $post->post_excerpt);
        $excerpt_rss = apply_filters('the_excerpt_rss', $excerpt);

        $parser = new tp_parser();
        // Same phrase-breaking rules as the page render (transposh.php), otherwise the
        // queued keys differ from the keys looked up when the page is displayed
        // (e.g. with "don't break punctuation" a title stays one phrase at render).
        $rules = $this->parsing_rules();
        $parser->change_parsing_rules($rules[0], $rules[1], $rules[2]);
        $phrases = array_merge($parser->get_phrases_list($content), $parser->get_phrases_list($title),
                $parser->get_phrases_list($the_content_feed), $parser->get_phrases_list($excerpt),
                $parser->get_phrases_list($excerpt_rss));
        $head = $this->get_rendered_head_phrases($postID);
        return array('title' => $title, 'content' => $phrases, 'head' => $head['phrases'], 'head_status' => $head['status']);
    }

    function register_rest_routes() {
        register_rest_route('transposh/v1', '/coverage/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(&$this, 'rest_coverage'),
            'permission_callback' => function ($request) {
                return current_user_can('manage_options') && current_user_can('edit_post', (int) $request['id']);
            },
            'args' => array('samples' => array('default' => 10, 'sanitize_callback' => 'absint')),
        ));
        register_rest_route('transposh/v1', '/translations', array(
            'methods' => 'POST',
            'callback' => array(&$this, 'rest_human_translations'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
        register_rest_route('transposh/v1', '/translate-missing/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array(&$this, 'rest_translate_missing'),
            'permission_callback' => function ($request) {
                return current_user_can('manage_options') && current_user_can('edit_post', (int) $request['id']);
            },
        ));
    }

    /**
     * Store reviewed translations as HUMAN translations (source 0), the same write path as
     * the on-page editor. Body: items = [{original, lang, translation}], dry_run (default true).
     * Returns the stored value before/after for every item, so the change can be reverted.
     */
    function rest_human_translations($request) {
        $items = $request->get_param('items');
        $dry_run = $request->get_param('dry_run') !== false && $request->get_param('dry_run') !== 'false' && $request->get_param('dry_run') !== 0;
        if (!is_array($items) || !$items || count($items) > 100) {
            return new WP_Error('tp_items', 'items must be a list of 1 to 100 {original, lang, translation}', array('status' => 400));
        }
        $db = $this->transposh->database;
        $out = array('dry_run' => $dry_run, 'items' => array());
        foreach ($items as $it) {
            $orig = isset($it['original']) ? (string) $it['original'] : '';
            $lang = isset($it['lang']) ? (string) $it['lang'] : '';
            $text = isset($it['translation']) ? (string) $it['translation'] : '';
            $row = array('original' => $orig, 'lang' => $lang, 'translation' => $text);
            if ($orig === '' || trim($text) === '' || !$this->transposh->options->is_active_language($lang)
                    || $this->transposh->options->is_default_language($lang)) {
                $row['status'] = 'invalid';
                $out['items'][] = $row;
                continue;
            }
            $before = $db->fetch_translation($orig, $lang);
            $row['before'] = is_array($before) ? array('source' => $before[0], 'translation' => $before[1]) : null;
            if (!$dry_run) {
                $saved_post = $_POST;
                ob_start(); // the legacy write path may echo (e.g. disabled upstream backup notice)
                try {
                    $_POST = array('items' => 1, 'ln0' => $lang, 'sr0' => 0, 'tk0' => $orig, 'tr0' => rawurlencode($text));
                    $db->update_translation((string) get_current_user_id());
                } finally {
                    ob_end_clean();
                    $_POST = $saved_post;
                }
                $after = $db->fetch_translation($orig, $lang);
                $row['after'] = is_array($after) ? array('source' => $after[0], 'translation' => $after[1]) : null;
                $row['status'] = (is_array($after) && (string) $after[0] === '0') ? 'stored_human' : 'not_stored';
            } else {
                $row['status'] = 'dry_run';
            }
            $out['items'][] = $row;
        }
        return rest_ensure_response($out);
    }

    /**
     * Phrases worth sending to a machine engine: not a bare URL, and containing at
     * least one letter (skips emoji, numbers and URLs collected from link/meta tags).
     * @param string $phrase
     * @return boolean
     */
    static function is_engine_translatable($phrase) {
        $text = trim(html_entity_decode((string) $phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '' || preg_match('~^(https?:)?//\S+$~i', $text) || preg_match('~^[\w.-]+\.[a-z]{2,}(/\S*)?$~i', $text)) {
            return false;
        }
        // technical <link title> labels: one word without context ("Feed" became "Futter"/"Pasz")
        $skip = function_exists('apply_filters') ? apply_filters('transposh_engine_skip_phrases',
                array('Feed', 'Comments Feed', 'RSD', 'oEmbed (JSON)', 'oEmbed (XML)', 'JSON', 'XML'))
                : array('Feed', 'Comments Feed', 'RSD', 'oEmbed (JSON)', 'oEmbed (XML)', 'JSON', 'XML');
        if (in_array($text, (array) $skip, true) || preg_match('~ (Comments )?Feed$~', $text)) {
            return false;
        }
        return (bool) preg_match('/\p{L}/u', $text);
    }

    /**
     * Server-side equivalent of the admin "translate all" for ONE post: machine-translate
     * the phrases that have no translation yet, through the configured engine, and store
     * them with the same path as on-page automatic translation (tp_tp proxy).
     * Never overwrites an existing (human or automatic) translation.
     * Body: dry_run (default true), langs (array, default all non-default viewable),
     *       groups (array of "content"/"head", default both), engine ("b" only), max (default 150 per language)
     */
    function rest_translate_missing($request) {
        $postID = (int) $request['id'];
        if (!get_post($postID)) {
            return new WP_Error('tp_no_post', 'Unknown post', array('status' => 404));
        }
        $dry_run = $request->get_param('dry_run') !== false && $request->get_param('dry_run') !== 'false' && $request->get_param('dry_run') !== 0;
        $engine = $request->get_param('engine') ?: 'b';
        if ($engine !== 'b') {
            return new WP_Error('tp_engine', 'Only the Microsoft Translator engine (b) is supported here', array('status' => 400));
        }
        $max = max(1, min(500, (int) ($request->get_param('max') ?: 150)));
        $groups = (array) ($request->get_param('groups') ?: array('content', 'head'));
        $viewable = array_filter(explode(',', $this->transposh->options->viewable_languages), function ($l) {
            return !$this->transposh->options->is_default_language($l);
        });
        $langs = $request->get_param('langs') ? array_values(array_intersect((array) $request->get_param('langs'), $viewable)) : array_values($viewable);

        $collected = $this->collect_post_phrases($postID);
        $phrases = array();
        foreach ($groups as $g) {
            if (isset($collected[$g]) && is_array($collected[$g])) {
                $phrases += $collected[$g];
            }
        }
        $sl = $this->transposh->options->default_language;
        $o = $this->transposh->options;
        $result = array('post' => $postID, 'dry_run' => $dry_run, 'head_status' => $collected['head_status'],
            // configuration presence only, never the key itself
            'engine' => array('key_present' => trim((string) (defined('TRANSPOSH_MSN_KEY') ? TRANSPOSH_MSN_KEY : $o->msn_key)) !== '',
                'region_set' => trim((string) $o->msn_region) !== '', 'curl' => function_exists('curl_init')),
            'languages' => array());
        if ($request->get_param('engine_test')) {
            // one tiny request to the Microsoft endpoint, reporting HTTP status and API error code only
            $endpoint = trim((string) $o->msn_endpoint);
            if ($endpoint === '' || stripos($endpoint, 'https://') !== 0) {
                $endpoint = 'https://api.cognitive.microsofttranslator.com';
            }
            $const = defined('TRANSPOSH_MSN_KEY') ? trim((string) TRANSPOSH_MSN_KEY) : null;
            $opt = trim((string) $o->msn_key);
            // which key the engine really uses: the wp-config constant wins over the settings field
            $result['engine']['key_source'] = $const !== null ? 'wp-config constant TRANSPOSH_MSN_KEY' : 'settings field';
            $result['engine']['settings_field_differs_from_constant'] = $const !== null ? ($opt !== $const) : null;
            $result['engine']['region'] = trim((string) $o->msn_region); // region is not secret
            $candidates = array('in_use' => $const !== null ? $const : $opt);
            if ($const !== null && $opt !== '' && $opt !== $const) {
                $candidates['settings_field'] = $opt;
            }
            foreach ($candidates as $label => $key) {
                $headers = array('Content-Type' => 'application/json', 'Ocp-Apim-Subscription-Key' => $key);
                if (trim((string) $o->msn_region) !== '') {
                    $headers['Ocp-Apim-Subscription-Region'] = trim((string) $o->msn_region);
                }
                $r = wp_remote_post(rtrim($endpoint, '/') . '/translate?api-version=3.0&to=fr&from=en',
                        array('timeout' => 10, 'headers' => $headers, 'body' => wp_json_encode(array(array('Text' => 'Hello')))));
                $body = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
                $result['engine']['test'][$label] = is_wp_error($r) ? array('transport_error' => $r->get_error_code())
                    : array('http' => wp_remote_retrieve_response_code($r), 'endpoint_host' => wp_parse_url($endpoint, PHP_URL_HOST),
                        'api_error' => $body['error'] ?? null, 'ok' => isset($body[0]['translations'][0]['text']));
            }
            return rest_ensure_response($result);
        }
        foreach ($langs as $lang) {
            if (!transposh_consts::is_supported_engine($lang, 'b')) {
                $result['languages'][$lang] = array('skipped' => 'engine does not support language');
                continue;
            }
            $missing = array();
            foreach ($phrases as $phrase) {
                $found = $this->transposh->database->fetch_translation($phrase, $lang);
                if ($found === false) {
                    continue; // lookup error: never guess
                }
                if (!(is_array($found) && $found[1]) && self::is_engine_translatable($phrase)) {
                    $missing[] = $phrase;
                }
            }
            $row = array('missing' => count($missing), 'requested' => 0, 'stored' => 0, 'pairs' => array());
            $missing = array_slice($missing, 0, $max);
            $row['requested'] = count($missing);
            if (!$missing || $dry_run) {
                $row['pairs'] = array_map(function ($p) { return array('original' => $p); }, $missing);
                $result['languages'][$lang] = $row;
                continue;
            }
            // the engine receives plain text (entities decoded); keys stay as collected
            $q = array_map(function ($p) { return urlencode(html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8')); }, $missing);
            $translated = array();
            foreach (array_chunk($q, 25, true) as $chunk) {
                $out = transposh_translate::get_bing_translation($lang, $sl, array_values($chunk));
                if (!is_array($out) || count($out) !== count($chunk)) {
                    $row['error'] = 'engine failure';
                    break;
                }
                foreach (array_values(array_keys($chunk)) as $n => $idx) {
                    $translated[$idx] = $out[$n];
                }
            }
            $saved_post = $_POST;
            try {
                $k = 0;
                $_POST = array('ln0' => $lang, 'sr0' => 2); // 2 = Microsoft Translator, as in the tp_tp proxy
                foreach ($translated as $idx => $text) {
                    if (!is_string($text) || trim($text) === '') {
                        continue;
                    }
                    $_POST["tk$k"] = $missing[$idx];
                    $_POST["tr$k"] = rawurlencode($text);
                    $row['pairs'][] = array('original' => $missing[$idx], 'translation' => $text);
                    $k++;
                }
                $_POST['items'] = $k;
                if ($k) {
                    // automatic source: update_translation() never replaces a human translation
                    ob_start();
                    try {
                        $this->transposh->database->update_translation((string) get_current_user_id());
                    } finally {
                        ob_end_clean();
                    }
                }
                $row['stored'] = $k;
            } finally {
                $_POST = $saved_post;
            }
            $result['languages'][$lang] = $row;
        }
        return rest_ensure_response($result);
    }

    /**
     * Read-only translation coverage of one post, per viewable language.
     * Source 0 = human, 1+ = automatic (see tp_parser::create_edit_span).
     */
    function rest_coverage($request) {
        $postID = (int) $request['id'];
        if (!get_post($postID)) {
            return new WP_Error('tp_no_post', 'Unknown post', array('status' => 404));
        }
        $samples = min(50, (int) $request['samples']);
        // diagnostic: ?probe=<text> shows how one phrase is stored and looked up (admin only, read-only)
        $probe = $request->get_param('probe');
        if (is_string($probe) && $probe !== '') {
            global $wpdb;
            $db = $this->transposh->database;
            $like = '%' . $wpdb->esc_like(substr($probe, 0, 120)) . '%';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT original, lang, source, CHAR_LENGTH(original) AS len, HEX(SUBSTRING(original,1,12)) AS hexstart FROM {$wpdb->prefix}" . TRANSLATIONS_TABLE . " WHERE original LIKE %s ORDER BY lang LIMIT 40", $like), ARRAY_A);
            $lookups = array();
            foreach (explode(',', $this->transposh->options->viewable_languages) as $lang) {
                $lookups[$lang] = $db->fetch_translation($probe, $lang);
            }
            return rest_ensure_response(array('probe' => $probe, 'rows_like' => $rows, 'fetch_translation' => $lookups));
        }
        $collected = $this->collect_post_phrases($postID);
        $groups = array('content' => $collected['content'], 'head' => $collected['head']);
        $max = (int) apply_filters('transposh_coverage_max_phrases', 2000);
        if (count($groups['content']) + count($groups['head']) > $max) {
            return new WP_Error('tp_too_many_phrases', "More than $max phrases", array('status' => 413));
        }
        $result = array('post' => $postID, 'url' => get_permalink($postID),
            // head counts are meaningless unless head_status is "ok"
            'head_status' => $collected['head_status'], 'languages' => array());
        foreach (explode(',', $this->transposh->options->viewable_languages) as $lang) {
            if ($this->transposh->options->is_default_language($lang)) {
                continue;
            }
            // one batched query per language instead of one per phrase
            $this->transposh->database->prefetch_translations(array_values(array_merge($groups['content'], $groups['head'])), $lang);
            foreach ($groups as $group => $phrases) {
                $row = array('total' => count($phrases), 'human' => 0, 'auto' => 0, 'missing' => 0, 'errors' => 0, 'missing_samples' => array());
                foreach ($phrases as $phrase) {
                    $found = $this->transposh->database->fetch_translation($phrase, $lang);
                    if ($found === false) {
                        $row['errors']++; // lookup failure, not a missing translation
                        continue;
                    }
                    list($source, $translation) = is_array($found) ? $found : array(null, null);
                    if (!$translation) {
                        $row['missing']++;
                        if (count($row['missing_samples']) < $samples) {
                            $row['missing_samples'][] = $phrase;
                        }
                    } elseif ((string) $source === '0') {
                        $row['human']++;
                    } else {
                        $row['auto']++;
                    }
                }
                $result['languages'][$lang][$group] = $row;
            }
        }
        return rest_ensure_response($result);
    }

    /**
     * Admin menu created action, where we create our metaboxes
     */
    function on_admin_menu() {
        //add our metaboxs to the post and publish pages
        tp_logger('adding metaboxes for admin pages/post/custom', 4);
        $post_types = get_post_types();
        foreach ($post_types as $post_type) {
            if (in_array($post_type, array('attachment', 'revision', 'nav_menu_item')))
                continue;
            tp_logger($post_type, 5);
            if ($this->transposh->options->enable_autoposttranslate) {
                add_meta_box('transposh_postpublish', __('Transposh', TRANSPOSH_TEXT_DOMAIN), array(&$this, "transposh_postpublish_box"), $post_type, 'side', 'core');
            }
            add_meta_box('transposh_setlanguage', __('Set post language', TRANSPOSH_TEXT_DOMAIN), array(&$this, "transposh_setlanguage_box"), $post_type, 'advanced', 'core');
        }
        if (!isset($_GET['post']))
            return;
        if (get_post_meta($_GET['post'], 'transposh_can_translate', true)) { // do isdefined stuff
            $this->just_published = true; // this is later used in the meta boxes //XXXXXXXXXXXXXXXXXXXXXXXXXXXX
            wp_enqueue_script("transposh_backend", $this->transposh->transposh_plugin_url . '/' . TRANSPOSH_DIR_JS . '/admin/backendtranslate.js', array('transposh'), TRANSPOSH_PLUGIN_VER, true);
            $enginelangs = '';
            foreach (transposh_consts::get_engines() as $engine => $engrec) {
                $enginelangs .= "t_be.{$engine}_langs = ". json_encode(implode(',',transposh_consts::get_engine_lang_codes($engine))).';';
            }
            $script_params = array(
                'post' => $_GET['post'],
                'l10n_print_after' => $enginelangs
            );
            wp_localize_script("transposh_backend", "t_be", $script_params);
            // MAKESURE 3.3
            if ( version_compare( $GLOBALS['wp_version'], '3.3', '>=' ) ) {
                wp_enqueue_script('jquery-ui-progressbar');
            }
            else {
                wp_enqueue_script('jqueryui', '//ajax.googleapis.com/ajax/libs/jqueryui/' . JQUERYUI_VER . '/jquery-ui.min.js', array('jquery'), JQUERYUI_VER, true);
            }
            wp_enqueue_style('jqueryui', '//ajax.googleapis.com/ajax/libs/jqueryui/' . JQUERYUI_VER . '/themes/ui-lightness/jquery-ui.css', array(), JQUERYUI_VER);            

            delete_post_meta($_GET['post'], 'transposh_can_translate'); // as we have used the meta - it can go now, another option would have been to put this in the getphrases
        }
    }

    /**
     * Function to allow mass translate of tags
     * @return array list of tags
     */
    function get_tags() {
        $tags = get_terms('post_tag'); // Always query top tags
        $phrases = array();
        foreach ($tags as $tag) {
            $phrases[] = $tag->name;
        }
        return $phrases;
    }

    /**
     * Loop through all the post phrases and return them in json formatted script
     * @param int $postID
     */
    function get_post_phrases($postID) {
        // Some security, to avoid others from seeing private posts
        // fake post for tags
        if ($postID == -555) {
            $phrases = $this->get_tags();
            $title = "tags";
        }
        // a normal post
        else {
            if (!current_user_can('edit_post', $postID))
                return;
            //TODO - get comments text
            $groups = $this->collect_post_phrases($postID);
            $title = $groups['title'];
            // content phrases plus SEO <head> phrases (title, description, OG, JSON-LD)
            $phrases = array_merge($groups['content'], $groups['head']);
            // surfaced to the admin translate-all UI so a failed collection is visible
            $json['head_status'] = $groups['head_status'];
            tp_logger($phrases, 4);

            // Add phrases from permalink
            if ($this->transposh->options->enable_url_translate) {
                $permalink = get_permalink($postID);
                $permalink = substr($permalink, strlen($this->transposh->home_url) + 1);
                $parts = explode('/', $permalink);
                foreach ($parts as $part) {
                    if (!$part || is_numeric($part))
                        continue;
                    $part = str_replace('-', ' ', $part);
                    $phrases[] = urldecode($part);
                }
            }
        }
        // We provide the post title here
        $json['posttitle'] = $title;
        // and all languages we might want to target
        $json['langs'] = array();

        foreach ($phrases as $key) {
            foreach (explode(',', $this->transposh->options->viewable_languages) as $lang) {
                // if this isn't the default language or we specifically allow default language translation, we will seek this out...
                // as we don't normally want to auto-translate the default language -FIX THIS to include only correct stuff, how?
                if (!$this->transposh->options->is_default_language($lang) || $this->transposh->options->enable_default_translate) {
                    // There is no point in returning phrases, languages pairs that cannot be translated
                    $is_language_enginetranslateable = false;
                    foreach (transposh_consts::get_engines() as $engine => $enginerec) {
                        if (transposh_consts::is_supported_engine($lang,$engine)) {
                            $is_language_enginetranslateable = true;
                        }
                    }
                    if ($is_language_enginetranslateable) {
                        list($source, $translation) = $this->transposh->database->fetch_translation($key, $lang);
                        if (!$translation) {
                            // p stands for phrases, l stands for languages, t is token
                            if (!@is_array($json['p'][$key]['l'])) {
                                $json['p'][$key]['l'] = array();
                            }
                            array_push($json['p'][$key]['l'], $lang);
                            if (!in_array($lang, $json['langs'])) {
                                array_push($json['langs'], $lang);
                            }
                        }
                    }
                }
            }
            // only if a languages list was created we'll need to translate this
            if (@is_array($json['p'][$key]['l'])) {
                //$json['p'][$key]['t'] = $key;//transposh_utils::base64_url_encode($key);
                @$json['length'] ++;
            }
        }


        // the header helps with debugging
        header("Content-type: text/javascript");
        echo json_encode($json);
    }

    /**
     * This is the box that appears on the side
     */
    function transposh_postpublish_box() {
        if (isset($_GET['post']) && get_post_meta($_GET['post'], 'transposh_can_translate', true))
            $this->just_published = true;

        if ($this->just_published) {
            echo '<div id="tr_loading">Publication happened - loading phrases list...</div>';
        } else {
            echo 'Waiting for publication';
        }
    }

    /**
     * This is a selection of language box which should hopefully appear below the post edit
     */
    function transposh_setlanguage_box() {
        $lang = get_post_meta($_GET['post'], 'tp_language', true);
        echo '<select name="transposh_tp_language">';
        echo '<option value="">' . __('Default') . '</option>';
        foreach ($this->transposh->options->get_sorted_langs() as $langcode => $langrecord ) {
            $langname = transposh_consts::get_language_name($langcode);
            $langorigname = transposh_consts::get_language_orig_name($langcode);
            echo '<option value="' . $langcode . ($langcode == $lang ? '" selected="selected' : '') . '">' . $langname . ' - ' . $langorigname . '</option>';
        }
        echo '</select>';
    }

    /**
     * When this happens, the boxes are not created we now use a meta to inform the next step (cleaner)
     * we now also update the tp_language meta for the post
     * @param int $postID
     */
    function on_edit($postID) {
        // This should prevent the meta from being added when not needed
        if (!isset($_POST['transposh_tp_language'])) {
            return;
        }
        if ($this->transposh->options->enable_autoposttranslate) {
            add_post_meta($postID, 'transposh_can_translate', 'true', true);
        }
        if ($_POST['transposh_tp_language'] == '') {
            delete_post_meta($postID, 'tp_language');
        } else {
            update_post_meta($postID, 'tp_language', $_POST['transposh_tp_language']);
            // if a language is set for a post, default language translate must be enabled, so we enable it
            if (!$this->transposh->options->enable_default_translate) {
                $this->transposh->options->enable_default_translate = true;
                $this->transposh->options->update_options();
            }
        }
        tp_logger($postID . ' ' . $_POST['transposh_tp_language']); //??
    }

}

?>