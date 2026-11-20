/* Transposh ObviousIdea — suggestion de langue non intrusive.
   Si la langue du navigateur diffère de la page visitée et est disponible,
   propose une bulle discrète en bas à gauche. "Non merci" = oublié à jamais
   (localStorage). Fermeture automatique = représentée à la visite suivante. */
(function () {
    'use strict';
    var DELAY_MS = 1600, AUTOHIDE_MS = 14000, KEY = '***';
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

    try {
        if (localStorage.getItem(KEY) === '1') return;
    } catch (e) { /* stockage privé : on propose quand même */ }

    // Langue courante = préfixe d'URL (/fr/..., /ja/...) sinon en
    var seg = (location.pathname.split('/')[1] || '').toLowerCase();
    var current = LANG_NAMES[seg] ? seg : 'en';

    // Langues réellement proposées par le widget rendu
    var widget = document.querySelector('.transposh_flags');
    if (!widget) return;
    var avail = {};
    var links = widget.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
        var s = links[i].getAttribute('href').split('/')[1] || '';
        var code = LANG_NAMES[s] ? s : 'en';
        avail[code] = links[i].getAttribute('href');
    }

    // Langue du navigateur réellement disponible : on s'arrête à la première
    // listée par le navigateur. Si c'est déjà la langue de la page : rien.
    var wanted = (navigator.languages || [navigator.language || navigator.userLanguage || 'en'])
        .map(function (l) { return (l || '').split('-')[0].toLowerCase(); });
    var target = null;
    for (var j = 0; j < wanted.length; j++) {
        if (avail[wanted[j]]) { target = wanted[j]; break; }
    }
    if (!target || target === current || !avail[target]) return;

    var flagCls = '';
    var span = widget.querySelector('span.trf-' + (target === 'en' ? 'us' : target));
    if (span) flagCls = 'trf trf-' + (target === 'en' ? 'us' : target);

    var css = document.createElement('style');
    css.textContent =
        '.tpw-suggest{position:fixed;left:16px;bottom:16px;z-index:99999;display:flex;align-items:center;gap:10px;' +
        'max-width:340px;padding:10px 12px;border-radius:12px;background:#fff;color:#1f2328;' +
        'box-shadow:0 6px 24px rgba(0,0,0,.16),0 1px 3px rgba(0,0,0,.08);font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;' +
        'opacity:0;transform:translateY(8px);transition:opacity .25s ease,transform .25s ease}' +
        '.tpw-suggest.on{opacity:1;transform:none}' +
        '.tpw-suggest .tpw-f{flex:0 0 auto;width:22px;height:13px;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12)}' +
        '.tpw-suggest .tpw-t{flex:1 1 auto}' +
        '.tpw-suggest button{border:0;border-radius:8px;padding:6px 12px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap}' +
        '.tpw-suggest .tpw-y{background:#2563eb;color:#fff}' +
        '.tpw-suggest .tpw-y:hover{background:#1d4ed8}' +
        '.tpw-suggest .tpw-n{background:transparent;color:#6b7280;font-weight:500}' +
        '.tpw-suggest .tpw-n:hover{color:#1f2328}' +
        '@media (max-width:480px){.tpw-suggest{left:12px;right:12px;max-width:none}}' +
        '@media (prefers-reduced-motion:reduce){.tpw-suggest{transition:none}}';
    document.head.appendChild(css);

    var box = document.createElement('div');
    box.className = 'tpw-suggest no_translate';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', 'language suggestion');
    box.innerHTML =
        '<span class="tpw-f ' + flagCls + '"></span>' +
        '<span class="tpw-t"></span>' +
        '<button class="tpw-y" type="button"></button>' +
        '<button class="tpw-n" type="button" aria-label="close"></button>';
    box.querySelector('.tpw-t').textContent = CTA[target] || ('View this page in ' + (LANG_NAMES[target] || target) + '?');
    box.querySelector('.tpw-y').textContent = YES[target] || 'Switch';
    box.querySelector('.tpw-n').textContent = '✕ ' + (NO[target] || 'No thanks');

    function dismiss(remember) {
        box.classList.remove('on');
        setTimeout(function () { box.remove(); }, 300);
        if (remember) { try { localStorage.setItem(KEY, '1'); } catch (e) {} }
    }
    box.querySelector('.tpw-y').addEventListener('click', function () {
        var href = avail[target];
        location.href = href;
    });
    box.querySelector('.tpw-n').addEventListener('click', function () { dismiss(true); });

    document.body.appendChild(box);
    setTimeout(function () {
        box.classList.add('on');
        setTimeout(function () { dismiss(false); }, AUTOHIDE_MS);
    }, DELAY_MS);
})();
