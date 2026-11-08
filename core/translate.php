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

/**
 *
 * Contains translation scraping functions
 *
 */
require_once("constants.php");
require_once("logging.php");

/**
 * This is a static class to reduce chance of namespace collisions with other plugins
 */
class transposh_translate
{
    /**
     * Executes a cURL request with the given URL, options, and headers.
     *
     * @param string $url The URL to request.
     * @param array $options Optional cURL options to set.
     * @param array $headers Optional headers to set for the request.
     * @return string|false The response from the cURL request, or false on failure.
     */
    private static function executeCurlRequest(string $url, array $options = [], array $headers = []): string|false {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $UA = transposh_utils::get_clean_server_var("HTTP_USER_AGENT", FILTER_DEFAULT);
        curl_setopt($ch, CURLOPT_USERAGENT, $UA);
        // set default timeouts
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        foreach ($options as $key => $value) {
            curl_setopt($ch, $key, $value);
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            tp_logger('cURL error: ' . curl_error($ch), 1);
            curl_close($ch);
            return false;
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            tp_logger("HTTP error: $httpCode for URL $url", 1);
            tp_logger("Response: $response", 1);
            return false;
        }
        return $response;
    }

    public static function getProtectedTerms(): array {
        global $my_transposh_plugin;

        // the UI example asks for comma separated terms; legacy stored lists are newline separated
        $raw_terms = preg_split('/[\R,]+/', (string) $my_transposh_plugin->options->protected_terms);
        $terms = array();
        foreach ($raw_terms as $term) {
            $term = trim((string) $term);
            if ($term !== '') {
                $terms[$term] = $term;
            }
        }

        uasort($terms, function ($left, $right) {
            return strlen($right) <=> strlen($left);
        });

        return array_values($terms);
    }

    private static function protectTermsInText(string $text, array $terms): array {
        if (!$terms || $text === '') {
            return array($text, array());
        }

        $placeholder_index = 0;
        $restore_map = array();
        foreach ($terms as $term) {
            $pattern = '~' . preg_quote($term, '~') . '~iu';
            $text = preg_replace_callback($pattern, function ($matches) use (&$placeholder_index, &$restore_map) {
                $placeholder = '__TP_PROTECTED_TERM_' . $placeholder_index . '__';
                $restore_map[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            }, $text);
        }

        return array($text, $restore_map);
    }

    private static function restoreProtectedTerms($translated, array $restore_map) {
        if (!$restore_map || $translated === false || $translated === null) {
            return $translated;
        }

        if (is_array($translated)) {
            return array_map(function ($item) use ($restore_map) {
                return is_string($item) ? strtr($item, $restore_map) : $item;
            }, $translated);
        }

        if (is_string($translated)) {
            // a placeholder the engine mangled or dropped means the term was lost: refuse the result
            foreach (array_keys($restore_map) as $placeholder) {
                if (strpos($translated, $placeholder) === false) {
                    return false;
                }
            }
            return strtr($translated, $restore_map);
        }

        return $translated;
    }

    /******************************************
     * Proxied Yandex translate suggestions
     *****************************************/
    public static function get_yandex_translation($tl, $sl, $q)
    {
        $sid = '';
        $timestamp = 0;
        if (get_option(TRANSPOSH_OPTIONS_YANDEXPROXY, array())) {
            list($sid, $timestamp) = get_option(TRANSPOSH_OPTIONS_YANDEXPROXY, array());
        }
        tp_logger("yandex sid $sid", 1);
        if ($sid == '') {
            if ((time() - TRANSPOSH_YANDEXPROXY_DELAY > $timestamp)) {
                // attempt key refresh on error
                $url = 'https://translate.yandex.com/';
                tp_logger($url, 1);
                $output = self::executeCurlRequest(
                    $url,
                    [CURLOPT_REFERER => "https://translate.yandex.com/"]
                );
                if ($output === false) {
                    tp_logger('Curl error during SID refresh');
                    return false;
                }

                $sidpos = strpos($output, "SID: '") + 6;
                $newout = substr($output, $sidpos);
                $sid = substr($newout, 0, strpos($newout, "',"));
                tp_logger("new sid: $sid", 1);
                // fix SID "encryption"
                $sid = implode(".", array_map(
                    function ($substring) {
                        return implode('', array_reverse(str_split($substring)));
                    },
                    explode(".", $sid)
                ));
                tp_logger("fixed sid: $sid", 1);
                update_option(TRANSPOSH_OPTIONS_YANDEXPROXY, array($sid, time()));

            }
        }

        if (!$sid) {
            tp_logger('No SID, gotta bail:' . $timestamp, 1);
            return false;
        }

        $sourceadd = '';
        if ($sl) {
            $sourceadd = "&source_lang={$sl}";
        }

        $url = "https://translate.yandex.net/api/v1/tr.json/translate?id={$sid}-0-0&srv=tr-text" .
            $sourceadd .
            "&target_lang={$tl}&reason=auto&format=text&strategy=0&disable_cache=false&ajax=1" .
            // "&yu=". not needed
            "";
            // "&sprvk=d"; captcha data

        // POST data
        $q = urldecode($q);
        $postData = [
            'text' => $q,
            'options' => 4
        ];
        $ch = curl_init();

        $output = self::executeCurlRequest(
            $url,
            [
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_REFERER => "https://translate.yandex.com/"
            ],
            [
                'Accept: */*',
                'X-Retpath-Y: https://translate.yandex.com',
                'Origin: https://translate.yandex.com',
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: cross-site',
                'TE: trailers'
            ]
        );

        tp_logger($output, 1);
        $jsonarr = json_decode($output);
        tp_logger($jsonarr, 3);
        if (!$jsonarr) {
            tp_logger('No JSON here, failing', 1);
            tp_logger($output, 3);
            return false;
        }
        if ($jsonarr->code != 200) {
            tp_logger('Some sort of error!', 1);
            tp_logger($output, 1);
            if ($jsonarr->code == 406 || $jsonarr->code == 405) { //invalid session
                update_option(TRANSPOSH_OPTIONS_YANDEXPROXY, array('', time()));
            }
            return false;
        }
        return $jsonarr->text;
    }

    /******************************************
     * Proxied Baidu translate suggestions
     ******************************************/
    public static function get_baidu_translation($tl, $sl, $q)
    {
        $attempt = 1;
        $q_was_array = is_array($q);
        // URL for the request
        $url = 'https://fanyi.baidu.com/ait/text/translate';
        tp_logger("Baidu translate", 1);

        // JSON payload
        if (is_array($q)) {
            $q = json_encode(array_map('urldecode', $q), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $q = urldecode($q);
        }
        $data = [
            "query" => $q,
            "from" => "en", // BUG
            "to" => transposh_consts::get_engine_lang_code($tl,'u'),
            // "reference" => "",
            // "corpusIds" => [],
            // "needPhonetic" => false,
            // "domain" => "common"
        ];

        $jsonData = json_encode($data);

        tp_logger("Baidu JSON data: $jsonData", 1);

        while ($attempt <= 3) {
            $response = self::executeCurlRequest(
                $url,
                [
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => $jsonData,
                    CURLOPT_SSL_VERIFYPEER => false
                ],
                [
                    'Accept: text/event-stream',
                    'Accept-Language: en-US,en;q=0.5',
                    'Accept-Encoding: gzip, deflate, br, zstd',
                    'Content-Type: application/json',
                    'Origin: https://fanyi.baidu.com',
                    'Sec-Fetch-Dest: empty',
                    'Sec-Fetch-Mode: cors',
                    'Sec-Fetch-Site: same-origin',
                    'Pragma: no-cache',
                    'Cache-Control: no-cache'
                ]
            );

            if ($response === false) {
                tp_logger('Baidu cURL error or HTTP error', 1);
                return false;
            }
            tp_logger($response, 3);
            // Since it's an event stream, process line by line
            $lines = explode("\n", $response);
            foreach ($lines as $line) {
                if (strpos($line, 'data:') === 0) {
                    $json = substr($line, 5); // Remove "data: " prefix
                    $decoded = json_decode($json, true);
                    if ($decoded) {
                        if (isset($decoded['data']['event']) && $decoded['data']['event'] == 'Translating') {
                            if ($q_was_array) {
                                tp_logger(json_decode($decoded['data']['list'][0]['dst'], true));
                                return json_decode($decoded['data']['list'][0]['dst'], true);
                            }
                            return $decoded['data']['list'][0]['dst'];
                        }
                    }
                }
            }
            tp_logger("Baidu: attempt $attempt failed", 1);
            sleep(rand(1,3)); // Wait before retrying
            $attempt++;
        }
        tp_logger("Baidu: No translation found in response - tried $attempt times", 1);
        return false;
    }

    /* helper function for Google Translate */
    private static function _bitwise_zfrs($a, $b)
    {
        if ($b == 0)
            return $a;
        return ($a >> $b) & ~(1 << (8 * PHP_INT_SIZE - 1) >> ($b - 1));
    }

    private static function hq($a, $chunk)
    {
        for ($offset = 0; $offset < strlen($chunk) - 2; $offset += 3) {
            $b = $chunk[$offset + 2];
            $b = ($b >= "a") ? ord($b) - 87 : intval($b);
            $b = ($chunk[$offset + 1] == "+") ? self::_bitwise_zfrs($a, $b) : $a << $b;
            $a = ($chunk[$offset] == "+") ? $a + $b & 4294967295 : $a ^ $b;
        }
        return $a;
    }

    /**
     * Hey googler, if you are reading this, it means that you are actually here, why won't we work together on this?
     */
    private static function iq(string $input, string $error): string
    {
        [$base, $key] = array_map('intval', explode('.', $error, 2));
        $value = $base;
        $inputLen = strlen($input);
        for ($i = 0; $i < $inputLen; $i++) {
            $value += ord($input[$i]);
            $value = self::hq($value, '+-a^+6');
        }
        $value = self::hq($value, '+-3^+b+-f');
        $value ^= $key;
        if ($value < 0) {
            $value = ($value & 0x7FFFFFFF) + 0x80000000;
        }
        $x = $value % 1E6;
        return "$x." . ($x ^ $base);
    }

    /******************************************
     * Proxied translation for Google Translate
     *****************************************/

    public static function get_google_translation($tl, $sl, $q)
    {
        if (get_option(TRANSPOSH_OPTIONS_GOOGLEPROXY, array())) {
            list($googlemethod, $timestamp) = get_option(TRANSPOSH_OPTIONS_GOOGLEPROXY, array());
            //$googlemethod = 0;
            //$timestamp = 0;
            tp_logger("Google method $googlemethod, " . date(DATE_RFC2822, $timestamp) . ", current:" . date(DATE_RFC2822, time()) . " Delay:" . TRANSPOSH_GOOGLEPROXY_DELAY, 1);
        } else {
            tp_logger("Google is clean", 1);
            $googlemethod = 0;
        }

        // we preserve the method, and will ignore lower methods for the given delay period
        if (isset($timestamp) && (time() - TRANSPOSH_GOOGLEPROXY_DELAY > $timestamp)) {
            delete_option(TRANSPOSH_OPTIONS_GOOGLEPROXY);
        }
        tp_logger('Google proxy initiated', 1);
        $qstr = '';
        $iqstr = '';
        if (is_array($q)) {
            foreach ($q as $v) {
                $qstr .= '&q=' . $v;
                $iqstr .= urldecode($v);
            }
        } else {
            $qstr = '&q=' . $q;
            $iqstr = urldecode($q);
        }

        $urls = array(
            'http://translate.google.com',
            'http://212.199.205.226',
            'http://74.125.195.138',
            'https://translate.googleapis.com');

        $attempt = 1;
        $failed = true;
        foreach ($urls as $gurl) {
            if ($googlemethod < $attempt && $failed) {
                $failed = false;
                tp_logger("Attempt: $attempt", 1);
                $url = $gurl . '/translate_a/t?client=te&v=1.0&tl=' . $tl . '&sl=' . $sl . '&tk=' . self::iq($iqstr, '406448.272554134');
                tp_logger($url, 3);
                tp_logger($q, 3);
                tp_logger($iqstr, 3);

                $options = [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $qstr,
                ];
                //if the attempt is 2 or more, we skip ipv6
                if ($attempt > 1) {
                    $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
                }

                $output = self::executeCurlRequest($url, $options);

                if ($output === false) {
                    tp_logger("method fail - $attempt", 1);
                    $failed = true;
                    update_option(TRANSPOSH_OPTIONS_GOOGLEPROXY, array($attempt, time()));
                }
                unset($info);
            }
            $attempt++;
        }

        // Maybe in the future we may attempt with a key
        if ($failed || $output === false) {
            tp_logger('out of options, die for the day!', 1);
            return false;
        }

        tp_logger($output, 3);

        $jsonarr = json_decode($output);
        if (!$jsonarr) {
            tp_logger("google didn't return Proper JSON, lets try to recover", 2);
            $newout = str_replace(',,', ',', $output);
            tp_logger($newout);
            $jsonarr = json_decode($newout);
            if (!$jsonarr) {
                tp_logger('No JSON here, failing');
                tp_logger($output, 3);
                return false;
            }
        }
        tp_logger($jsonarr);
        if (is_array($jsonarr)) {
            if (is_array($jsonarr[0])) {
                foreach ($jsonarr as $val) {
                    // need to drill
                    while (is_array($val)) {
                        $val = $val[0];
                    }
                    $result[] = $val;
                }
            } else {
                // yes - it was all that was needed to fix the Google 2022 translation change
                $result = $jsonarr;
            }
        } else {
            $result[] = $jsonarr;
        }
        return $result;
    }

    /******************************************
     * Microsoft Translator (official API)
     *****************************************/

    private static function getMicrosoftTranslatorConfig(): array {
        global $my_transposh_plugin;

        // a wp-config.php constant wins, so the key does not have to live in wp_options
        $key = trim((string)(defined('TRANSPOSH_MSN_KEY') ? TRANSPOSH_MSN_KEY : $my_transposh_plugin->options->msn_key));
        $region = trim((string)$my_transposh_plugin->options->msn_region);
        $endpoint = trim((string)$my_transposh_plugin->options->msn_endpoint);

        // the subscription key travels in a header: never send it over clear http; empty endpoint falls back to https
        if ($endpoint === '' || stripos($endpoint, 'https://') !== 0) {
            $endpoint = 'https://api.cognitive.microsofttranslator.com';
        }

        return array(
            'key' => $key,
            'region' => $region,
            'endpoint' => rtrim($endpoint, '/')
        );
    }

    public static function getBingTranslatorTokens() {
        if (get_option(TRANSPOSH_OPTIONS_BINGPROXY, array())) {
            list($tokens, $timestamp) = get_option(TRANSPOSH_OPTIONS_BINGPROXY, array());
            // If keys are still valid, return them
            if ((time() - TRANSPOSH_BINGPROXY_DELAY < $timestamp) && (!empty($tokens['IG']) && !empty($tokens['IID']) && !empty($tokens['key']) && !empty($tokens['token']))) {
                tp_logger("using saved Bing translator tokens", 1);
                return $tokens;
            }
        }
        tp_logger("getting new Bing translator tokens", 1);
        $url = "https://www.bing.com/translator";
        $response = self::executeCurlRequest($url, [CURLOPT_FOLLOWLOCATION => true]);
        if ($response === false) {
            tp_logger("Error: Unable to fetch Bing translator page and keys.", 1);
            return ['IG' => '', 'IID' => '', 'key' => '', 'token' => ''];
        }
        // Extract IG (Instance GUID) and token
        preg_match('/IG:"([a-zA-Z0-9_-]+)"/', $response, $ig_matches);
        preg_match('/data-iid="([a-zA-Z0-9._-]+)"/', $response, $iid_matches);
        preg_match('/params_AbusePreventionHelper\s*=\s*\[(\d+),\s*"([^"]+)",\s*\d+\]/', $response, $token_matches);

        $tokens = [
            'IG' => $ig_matches[1] ?? '',
            'IID' => $iid_matches[1] ?? '',
            'key' => $token_matches[1] ?? '',
            'token' => $token_matches[2] ?? ''
        ];
        update_option(TRANSPOSH_OPTIONS_BINGPROXY, array($tokens, time()));
        return $tokens;
    }

    public static function get_bing_translation($tl, $sl, $q)
    {
        $q_was_array = is_array($q);
        $config = self::getMicrosoftTranslatorConfig();
        $protected_terms = self::getProtectedTerms();
        if ($config['key'] === '') {
            tp_logger("Error: Microsoft Translator API key is missing.", 1);
            return false;
        }

        $tl = transposh_consts::get_engine_lang_code($tl, 'b');
        if (!$tl) {
            tp_logger("Error: Unsupported Microsoft Translator target language.", 1);
            return false;
        }

        $source_lang = '';
        if ($sl) {
            $source_lang = transposh_consts::get_engine_lang_code($sl, 'b');
        }

        $query_args = array('api-version=3.0', 'to=' . rawurlencode($tl));
        if ($source_lang) {
            $query_args[] = 'from=' . rawurlencode($source_lang);
        }
        $url = $config['endpoint'] . '/translate?' . implode('&', $query_args);

        $restore_maps = array();
        if ($q_was_array) {
            $request_body = array();
            foreach ($q as $index => $item) {
                list($protected_text, $restore_map) = self::protectTermsInText(urldecode($item), $protected_terms);
                $restore_maps[$index] = $restore_map;
                $request_body[] = array('Text' => $protected_text);
            }
        } else {
            list($protected_text, $restore_map) = self::protectTermsInText(urldecode($q), $protected_terms);
            $restore_maps[0] = $restore_map;
            $request_body = array(array('Text' => $protected_text));
        }

        $headers = array(
            'Content-Type: application/json',
            'Ocp-Apim-Subscription-Key: ' . $config['key']
        );
        if ($config['region'] !== '') {
            $headers[] = 'Ocp-Apim-Subscription-Region: ' . $config['region'];
        }

        $response = self::executeCurlRequest(
            $url,
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => wp_json_encode($request_body)
            ],
            $headers
        );
        if ($response === false) {
            tp_logger("Error: Microsoft Translator cURL or HTTP error.", 1);
            return false;
        }
        tp_logger("Microsoft Translator response: $response", 3);
        $data = json_decode($response, true);

        if (!is_array($data) || empty($data)) {
            tp_logger("Error: Unable to parse Microsoft Translator response.", 1);
            return false;
        }

        if ($q_was_array) {
            $result = array();
            foreach ($data as $item) {
                if (!isset($item['translations'][0]['text'])) {
                    tp_logger("Error: Missing translated item in Microsoft Translator batch response.", 1);
                    return false;
                }
                $result[] = self::restoreProtectedTerms($item['translations'][0]['text'], $restore_maps[count($result)] ?? array());
            }
            return $result;
        }

        if (isset($data[0]['translations'][0]['text'])) {
            return self::restoreProtectedTerms($data[0]['translations'][0]['text'], $restore_maps[0] ?? array());
        }

        tp_logger("Error: Unable to parse Microsoft Translator response.", 1);
        return false;
    }

    private static function getOllamaConfig(): array {
        global $my_transposh_plugin;

        return array(
            'endpoint' => rtrim(trim((string)$my_transposh_plugin->options->ollama_endpoint), '/'),
            'api_key' => trim((string)$my_transposh_plugin->options->ollama_api_key),
            'model' => trim((string)$my_transposh_plugin->options->ollama_model),
            'prompt' => trim((string)$my_transposh_plugin->options->ollama_prompt),
        );
    }

    private static function getOllamaHeaders(string $api_key): array {
        $headers = array('Content-Type: application/json');
        if ($api_key !== '') {
            $headers[] = 'Authorization: Bearer ' . $api_key;
            $headers[] = 'X-API-Key: ' . $api_key;
        }
        return $headers;
    }

    private static function isNativeOllamaEndpoint(string $endpoint, string $api_key): bool {
        static $cache = array();

        $cache_key = md5($endpoint . '|' . $api_key);
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $headers = self::getOllamaHeaders($api_key);
        $base_endpoint = preg_replace('#/api/(generate|chat|tags)$#', '', $endpoint);
        $base_endpoint = rtrim($base_endpoint, '/');

        $candidates = array(
            $base_endpoint . '/api/tags',
            $base_endpoint,
        );

        foreach ($candidates as $candidate) {
            $response = self::executeCurlRequest($candidate, array(), $headers);
            if ($response === false) {
                continue;
            }

            $decoded = json_decode($response, true);
            if (is_array($decoded) && isset($decoded['models'])) {
                $cache[$cache_key] = true;
                return true;
            }

            if (stripos($response, 'Ollama is running') !== false) {
                $cache[$cache_key] = true;
                return true;
            }
        }

        $cache[$cache_key] = false;
        return false;
    }

    private static function getNativeOllamaGenerateEndpoint(string $endpoint): string {
        if (preg_match('#/api/generate$#', $endpoint)) {
            return $endpoint;
        }
        if (preg_match('#/api/chat$#', $endpoint)) {
            return preg_replace('#/api/chat$#', '/api/generate', $endpoint);
        }
        if (preg_match('#/api/tags$#', $endpoint)) {
            return preg_replace('#/api/tags$#', '/api/generate', $endpoint);
        }
        return rtrim($endpoint, '/') . '/api/generate';
    }

    private static function formatOllamaPrompt(string $template, string $text, string $source_lang, string $target_lang): string {
        $source_name = transposh_consts::get_language_name($source_lang);
        $target_name = transposh_consts::get_language_name($target_lang);

        return strtr($template, array(
            'XXX' => $text,
            '{{text}}' => $text,
            '{{source_language}}' => $source_name ?: $source_lang,
            '{{source_language_code}}' => $source_lang,
            '{{target_language}}' => $target_name ?: $target_lang,
            '{{target_language_code}}' => $target_lang,
        ));
    }

    private static function parseOllamaResponse(string $response) {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['translation']) && is_string($data['translation'])) {
            return $data['translation'];
        }
        if (isset($data['response']) && is_string($data['response'])) {
            return $data['response'];
        }
        if (isset($data['message']['content']) && is_string($data['message']['content'])) {
            return $data['message']['content'];
        }
        if (isset($data['choices'][0]['message']['content']) && is_string($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
        if (isset($data['choices'][0]['text']) && is_string($data['choices'][0]['text'])) {
            return $data['choices'][0]['text'];
        }

        return false;
    }

    private static function requestOllamaTranslation(string $endpoint, string $api_key, string $model, string $prompt, string $source_lang, string $target_lang, string $text) {
        list($protected_text, $restore_map) = self::protectTermsInText($text, self::getProtectedTerms());
        $final_prompt = self::formatOllamaPrompt($prompt, $protected_text, $source_lang, $target_lang);
        $headers = self::getOllamaHeaders($api_key);

        if (self::isNativeOllamaEndpoint($endpoint, $api_key)) {
            if ($model === '') {
                tp_logger("Error: Ollama model is missing for native Ollama endpoint.", 1);
                return false;
            }

            $payload = array(
                'model' => $model,
                'prompt' => $final_prompt,
                'stream' => false,
                'options' => array(
                    'num_predict' => 120
                )
            );

            $response = self::executeCurlRequest(
                self::getNativeOllamaGenerateEndpoint($endpoint),
                array(
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => wp_json_encode($payload)
                ),
                $headers
            );

            if ($response === false) {
                tp_logger("Error: Ollama native request failed.", 1);
                return false;
            }

            $parsed = self::parseOllamaResponse($response);
            if ($parsed === false) {
                tp_logger("Error: Unable to parse native Ollama response.", 1);
                tp_logger($response, 3);
            }

            return self::restoreProtectedTerms($parsed, $restore_map);
        }

        $payload = array(
            'text' => $text,
            'source_language' => $source_lang,
            'target_language' => $target_lang,
            'model' => $model,
            'prompt' => $final_prompt,
            'stream' => false,
            'messages' => array(
                array('role' => 'user', 'content' => $final_prompt)
            )
        );

        $response = self::executeCurlRequest(
            $endpoint,
            array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => wp_json_encode($payload)
            ),
            $headers
        );

        if ($response === false) {
            tp_logger("Error: Ollama cURL or HTTP error.", 1);
            return false;
        }

        $parsed = self::parseOllamaResponse($response);
        if ($parsed === false) {
            tp_logger("Error: Unable to parse Ollama response.", 1);
            tp_logger($response, 3);
        }

        return self::restoreProtectedTerms($parsed, $restore_map);
    }

    public static function get_ollama_translation($tl, $sl, $q)
    {
        $config = self::getOllamaConfig();
        if ($config['endpoint'] === '') {
            tp_logger("Error: Ollama endpoint is missing.", 1);
            return false;
        }
        if (!self::isNativeOllamaEndpoint($config['endpoint'], $config['api_key']) && $config['model'] === '') {
            tp_logger("Warning: Ollama model is empty, relying on custom endpoint defaults.", 2);
        }
        if ($config['prompt'] === '') {
            tp_logger("Error: Ollama prompt is missing.", 1);
            return false;
        }

        $target_lang = transposh_consts::get_engine_lang_code($tl, 'o');
        $source_lang = $sl ? transposh_consts::get_engine_lang_code($sl, 'o') : '';
        if (!$target_lang) {
            tp_logger("Error: Unsupported Ollama target language.", 1);
            return false;
        }

        if (is_array($q)) {
            $result = array();
            foreach ($q as $item) {
                $translated = self::requestOllamaTranslation(
                    $config['endpoint'],
                    $config['api_key'],
                    $config['model'],
                    $config['prompt'],
                    $source_lang,
                    $target_lang,
                    urldecode($item)
                );
                if ($translated === false) {
                    return false;
                }
                $result[] = $translated;
            }
            return $result;
        }

        return self::requestOllamaTranslation(
            $config['endpoint'],
            $config['api_key'],
            $config['model'],
            $config['prompt'],
            $source_lang,
            $target_lang,
            urldecode($q)
        );
    }

    /******************************************
     * Proxied translation for LibreTranslate
     *****************************************/
    public static function get_libretranslate_translation($tl, $sl, $q)
    {
        $q_was_array = is_array($q);
        global $my_transposh_plugin;
        $url = $my_transposh_plugin->options->libretranslate_server;
        if (!$url) {
            $url = TRANSPOSH_LIBRETRANSLATE_SERVICE_URL;
        }

        // Prepare payload
        $data = [
            'q' => $q_was_array ? array_map('urldecode', $q) : urldecode($q),
            'source' => 'auto',
            'target' => $tl,
            'format' => 'text'
        ];
        $jsonData = json_encode($data);

        $response = self::executeCurlRequest(
            $url,
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonData,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json']
            ]
        );

        if ($response === false) {
            tp_logger('LibreTranslate cURL or HTTP error', 1);
            return false;
        }

        $result = json_decode($response, true);
        if (!isset($result['translatedText'])) {
            tp_logger('LibreTranslate: No translatedText in response', 1);
            tp_logger($response, 3);
            return false;
        }
        tp_logger($response, 1);
        return $result['translatedText'];
    }

    /******************************************
     * Proxied translation for Apertium
     *****************************************/
    public static function get_apertium_translation($tl, $sl, $q)
    {
        $q_was_array = is_array($q);

        if (is_array($q)) {
            $q = json_encode(array_map('urldecode', $q), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $langpair = $sl . '|' . $tl;
        $params = array(
            'langpair' => $langpair,
            'markUnknown' => 'no',
            'q' => $q,
        );

        $url = 'https://apertium.org/apy/translate?' . http_build_query($params);

        $response = self::executeCurlRequest($url, [], []);
        if ($response === false) {
            return false;
        }

        $json = json_decode($response, true);
        if ($q_was_array) {
            tp_logger(json_decode($json['responseData']['translatedText'], true));
            return json_decode($json['responseData']['translatedText'], true);
        } else {
            return $json['responseData']['translatedText'];
        }
    }
}
