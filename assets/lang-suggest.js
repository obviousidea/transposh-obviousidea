/* Transposh ObviousIdea — suggestion de langue non intrusive.
   Si la langue du navigateur diffère de la page visitée et est disponible,
   propose une bulle discrète en bas (début de ligne). "Non merci" = oublié 90 jours
   (localStorage, timestamp). Fermeture automatique = pas re-proposée pendant la
   session (sessionStorage), représentée à la visite suivante. */
(function () {
    'use strict';
    var DELAY_MS = 1600, AUTOHIDE_MS = 14000;
    var KEY = 'tpw_lang_suggest_no', SEEN_KEY = 'tpw_lang_suggest_seen', TTL_MS = 90 * 24 * 3600 * 1000;
    var LANG_NAMES = {
        en: 'English', fr: 'Français', de: 'Deutsch', es: 'Español',
        it: 'Italiano', pl: 'Polski', pt: 'Português', nl: 'Nederlands', ja: '日本語'
    };
    var CTA = {
        en: 'View this page in English?', fr: 'Voir cette page en Français ?',
        de: 'Diese Seite auf Deutsch ansehen?', es: '¿Ver esta página en Español?',
        it: 'Vedere questa pagina in Italiano?', pl: 'Zobaczyć tę stronę po polsku?',
        pt: 'Ver esta página em Português?', nl: 'Deze pagina in het Nederlands bekijken?',
        ja: 'このページを日本語で表示しますか？'
    };
    var YES = { en: 'Switch', fr: 'Oui', de: 'Ja', es: 'Sí', it: 'Sì', pl: 'Tak', pt: 'Sim', nl: 'Ja', ja: 'はい' };
    var NO = { en: 'No thanks', fr: 'Non merci', de: 'Nein danke', es: 'No gracias', it: 'No grazie', pl: 'Nie dziękuję', pt: 'Não obrigado', nl: 'Nee bedankt', ja: '結構です' };
    var LABEL = {
        en: 'Language suggestion', fr: 'Suggestion de langue', de: 'Sprachvorschlag', es: 'Sugerencia de idioma',
        it: 'Suggerimento lingua', pl: 'Propozycja języka', pt: 'Sugestão de idioma', nl: 'Taalsuggestie', ja: '言語の提案'
    };
    var ALIAS = { nb: 'no', nn: 'no', iw: 'he', 'in': 'id', fil: 'tl' };

    function norm(l) {
        var c = String(l || '').split(/[-_]/)[0].toLowerCase();
        return ALIAS[c] || c;
    }

    try {
        var no = parseInt(localStorage.getItem(KEY), 10);
        if (no && Date.now() - no < TTL_MS) return;
        if (sessionStorage.getItem(SEEN_KEY) === '1') return;
    } catch (e) { /* stockage privé : on propose quand même */ }

    // Langue courante : <html lang> sinon préfixe d'URL (/fr/..., /ja/...) sinon en
    var htmlLang = norm(document.documentElement.lang);
    var seg = (location.pathname.split('/')[1] || '').toLowerCase();
    var current = htmlLang || (LANG_NAMES[seg] ? seg : 'en');

    // Langues réellement proposées par le widget rendu
    var widget = document.querySelector('.transposh_flags');
    if (!widget) return;
    var avail = {};
    var links = widget.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
        var href = links[i].getAttribute('href'), path;
        try { path = new URL(href, location.href).pathname; } catch (e) { continue; }
        var s = norm(path.split('/')[1] || '');
        if (LANG_NAMES[s]) { avail[s] = href; }
        else if (!avail.en && !/^(wp-admin|wp-content|wp-includes|wp-json|xmlrpc|feed)/.test(s)) { avail.en = href; } // English = default language, unprefixed URLs
    }

    // Si la langue de la page figure n'importe où dans les préférences du
    // navigateur : rien. Sinon, première langue listée qui est disponible.
    var wanted = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || navigator.userLanguage || 'en'])
        .map(norm);
    if (wanted.indexOf(current) !== -1) return;
    var target = null;
    for (var j = 0; j < wanted.length; j++) {
        if (avail[wanted[j]]) { target = wanted[j]; break; }
    }
    if (!target) return;

    var flagCode = target === 'en' ? 'us' : target;
    var flagCls = widget.querySelector('span.trf-' + flagCode) ? 'trf trf-' + flagCode : '';

    var css = document.createElement('style');
    css.textContent =
        '.tpw-suggest{position:fixed;inset-inline-start:16px;bottom:calc(16px + env(safe-area-inset-bottom,0px));z-index:99999;display:flex;align-items:center;gap:10px;' +
        'max-width:340px;padding:10px 12px;border-radius:12px;background:#fff;color:#1f2328;' +
        'box-shadow:0 6px 24px rgba(0,0,0,.16),0 1px 3px rgba(0,0,0,.08);font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;' +
        'opacity:0;transform:translateY(8px);transition:opacity .25s ease,transform .25s ease}' +
        '.tpw-suggest.on{opacity:1;transform:none}' +
        '.tpw-suggest .tpw-f{flex:0 0 auto;width:20px;height:15px;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12)}' +
        '.tpw-suggest .tpw-t{flex:1 1 auto}' +
        '.tpw-suggest button{border:0;border-radius:8px;padding:6px 12px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap}' +
        '.tpw-suggest .tpw-y{background:#2563eb;color:#fff}' +
        '.tpw-suggest .tpw-y:hover{background:#1d4ed8}' +
        '.tpw-suggest .tpw-n{background:transparent;color:#6b7280;font-weight:500}' +
        '.tpw-suggest .tpw-n:hover{color:#1f2328}' +
        '@media (max-width:480px){.tpw-suggest{inset-inline:12px;max-width:none}}' +
        '@media (prefers-reduced-motion:reduce){.tpw-suggest{transition:none}}';
    document.head.appendChild(css);

    var box = document.createElement('div');
    box.className = 'tpw-suggest no_translate';
    box.setAttribute('role', 'region');
    box.setAttribute('aria-label', LABEL[target] || LABEL.en);
    box.lang = target;
    box.innerHTML =
        '<span class="tpw-f ' + flagCls + '" aria-hidden="true"></span>' +
        '<span class="tpw-t"></span>' +
        '<button class="tpw-y" type="button"></button>' +
        '<button class="tpw-n" type="button"><span aria-hidden="true">✕</span> <span class="tpw-nt"></span></button>';
    box.querySelector('.tpw-t').textContent = CTA[target];
    box.querySelector('.tpw-y').textContent = YES[target];
    box.querySelector('.tpw-nt').textContent = NO[target];

    var timer = null, remaining = AUTOHIDE_MS, startedAt = 0, gone = false;
    function startTimer() {
        if (gone || timer) return;
        startedAt = Date.now();
        timer = setTimeout(function () { dismiss(false); }, remaining);
    }
    function pauseTimer() {
        if (!timer) return;
        clearTimeout(timer);
        timer = null;
        remaining = Math.max(1000, remaining - (Date.now() - startedAt));
    }
    function dismiss(remember) {
        if (gone) return;
        gone = true;
        pauseTimer();
        document.removeEventListener('keydown', onKey);
        box.classList.remove('on');
        setTimeout(function () { box.remove(); }, 300);
        try {
            if (remember) localStorage.setItem(KEY, String(Date.now()));
            else sessionStorage.setItem(SEEN_KEY, '1');
        } catch (e) {}
    }
    function onKey(e) {
        if (e.key === 'Escape' || e.key === 'Esc') dismiss(false);
    }
    box.querySelector('.tpw-y').addEventListener('click', function () {
        location.href = avail[target];
    });
    box.querySelector('.tpw-n').addEventListener('click', function () { dismiss(true); });
    box.addEventListener('mouseenter', pauseTimer);
    box.addEventListener('mouseleave', function () { if (!box.matches(':focus-within')) startTimer(); });
    box.addEventListener('focusin', pauseTimer);
    box.addEventListener('focusout', function () { if (!box.matches(':hover')) startTimer(); });
    document.addEventListener('keydown', onKey);

    document.body.appendChild(box);
    setTimeout(function () {
        if (gone) return;
        box.classList.add('on');
        startTimer();
    }, DELAY_MS);
})();
