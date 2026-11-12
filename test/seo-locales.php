<?php
/** Parser integration checks; callable under WordPress, no database writes. */
function transposh_test_seo_locales() {
    $checks = array();
    $render = static function($lang, $head) {
        $p = new tp_parser(); $p->lang = $lang; $p->is_auto_translate = false;
        $p->is_edit_mode = false; $p->allow_ad = false;
        $p->fetch_translate_func = static function($text, $language) {
            if ($text === 'de_DE') return array(0, 'INCORRECT DICTIONARY LOCALE');
            return $text === 'SEO description' ? array(0, 'Translated description') : null;
        };
        $p->url_rewrite_func = static function($url) { return $url; };
        return (string) $p->fix_html('<html><head>'.$head.'</head><body><p>Unchanged</p></body></html>');
    };
    foreach (array('fr'=>'fr_FR','de'=>'de_DE','es'=>'es_ES','it'=>'it_IT','pt'=>'pt_PT','pl'=>'pl_PL','pt-br'=>'pt_BR','zh-cn'=>'zh_CN') as $lang=>$locale) {
        $out = $render($lang, '<meta property="og:locale" content="en_US"><meta property="og:locale:alternate" content="de_DE">');
        $checks['locale_'.$lang] = strpos($out,'property="og:locale" content="'.$locale.'"') !== false;
        $checks['alternate_'.$lang] = strpos($out,'property="og:locale:alternate" content="de_DE"') !== false;
        $checks['body_'.$lang] = strpos($out,'<p>Unchanged</p>') !== false;
    }
    $checks['no_tag_injection'] = strpos($render('fr','<title>Unchanged</title>'),'og:locale') === false;
    $out = $render('unknown','<meta property="og:locale" content="en_US">');
    $checks['unknown_preserves_original'] = strpos($out,'content="en_US"') !== false;
    $option_filter = static function() { return array('fr'=>array('locale'=>'fr_BE')); };
    add_filter('pre_option_'.TRANSPOSH_OPTIONS_LANGUAGE_OVERRIDES,$option_filter);
    $checks['site_locale_override'] = strpos($render('fr','<meta property="og:locale" content="en_US">'),'content="fr_BE"') !== false;
    remove_filter('pre_option_'.TRANSPOSH_OPTIONS_LANGUAGE_OVERRIDES,$option_filter);
    $filter = static function($locale,$lang) { return $lang === 'fr' ? 'FR-ca' : $locale; };
    add_filter('tp_language_locale',$filter,10,2);
    $checks['locale_filter_normalized'] = strpos($render('fr','<meta property="og:locale" content="en_US">'),'content="fr_CA"') !== false;
    remove_filter('tp_language_locale',$filter,10);
    $filter = static function($locale) { return 'fr_FR"><script>alert(1)</script>'; };
    add_filter('tp_language_locale',$filter);
    $checks['invalid_locale_preserves_original'] = strpos($render('fr','<meta property="og:locale" content="en_US">'),'content="en_US"') !== false;
    remove_filter('tp_language_locale',$filter);
    $graph = array('@context'=>'https://schema.org','@graph'=>array(array('@type'=>'WebPage','@id'=>'https://unit.invalid/#page','description'=>'SEO description'),array('@type'=>'Organization','name'=>'Light Image Resizer','identifier'=>'en_US','logo'=>array('@type'=>'ImageObject','url'=>'https://cdn.invalid/logo.png'))));
    $out = $render('fr','<script type="application/ld+json">'.wp_json_encode($graph).'</script>');
    preg_match('~<script type="application/ld\+json">(.*?)</script>~s',$out,$m);
    $decoded = json_decode($m[1]??'',true);
    $graph['@graph'][0]['description']='Translated description';
    $checks['graph_semantically_preserved'] = $decoded === $graph;
    $out = $render('fr','<script type="application/ld+json">{invalid}</script>');
    $checks['invalid_json_untouched'] = strpos($out,'{invalid}') !== false;
    return $checks;
}
