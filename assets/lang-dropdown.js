/* Transposh ObviousIdea — modern language dropdown.
   Transforme le widget drapeaux en pilule compacte (drapeau + langue + chevron)
   ouvrant un panneau listant les langues (drapeau + nom natif + coche sur la langue
   active). Progressif : sans JS, la rangee de drapeaux reste telle quelle.
   La bulle lang-suggest.js reste chargee de capter l'attention ; le dropdown
   est le point d'entree discret. */
(function () {
    'use strict';
    var NAMES = {
        en: 'English', fr: 'Français', de: 'Deutsch', es: 'Español',
        it: 'Italiano', pl: 'Polski', pt: 'Português', nl: 'Nederlands', ja: '日本語'
    };
    var ALIAS = { nb: 'no', nn: 'no', iw: 'he', 'in': 'id', fil: 'tl' };
    var BTN_LABEL = {
        en: 'Language: ', fr: 'Langue : ', de: 'Sprache: ', es: 'Idioma: ', it: 'Lingua: ',
        pl: 'Język: ', pt: 'Idioma: ', nl: 'Taal: ', ja: '言語: '
    };
    function norm(l) {
        var c = String(l || '').split(/[-_]/)[0].toLowerCase();
        return ALIAS[c] || c;
    }
    // Segment d'URL : correspondance STRICTE (un slug /de-stress/ ne doit pas passer pour de)
    function segLang(seg) {
        var s = String(seg || '').toLowerCase();
        return NAMES[s] ? s : '';
    }

    var widgets = document.querySelectorAll('.transposh_flags');
    if (!widgets.length) return;

    // Langue courante : <html lang> sinon prefixe d'URL sinon en
    var htmlLang = norm(document.documentElement.lang);
    var seg = segLang(location.pathname.split('/')[1] || '');
    var current = NAMES[htmlLang] ? htmlLang : (NAMES[seg] ? seg : 'en');

    // Recenser les langues proposees par tous les widgets de la page
    var avail = {};
    for (var w = 0; w < widgets.length; w++) {
        var links = widgets[w].querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href'), path;
            try { path = new URL(href, location.href).pathname; } catch (e) { continue; }
            var s = segLang(path.split('/')[1] || '');
            if (NAMES[s]) { avail[s] = href; }
            else if (!avail.en && !/^(wp-admin|wp-content|wp-includes|wp-json|xmlrpc|feed)/.test(s)) { avail.en = href; }
        }
    }
    var codes = Object.keys(avail);
    if (codes.length < 2) return;

    var css = document.createElement('style');
    css.textContent =
        '.tpw-dd{position:relative;display:inline-block;font:14px/1.3 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}' +
        '.tpw-dd-btn{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid rgba(0,0,0,.12);border-radius:10px;' +
        'background:#fff;color:#1f2328;cursor:pointer;font:inherit;font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:box-shadow .15s ease,border-color .15s ease}' +
        '.tpw-dd-btn:hover,.tpw-dd-btn[aria-expanded="true"]{border-color:rgba(0,0,0,.28);box-shadow:0 2px 8px rgba(0,0,0,.10)}' +
        '.tpw-dd-btn:focus-visible{outline:2px solid #2563eb;outline-offset:2px}' +
        '.tpw-dd-btn .tpw-ddf{width:20px;height:15px;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12);flex:0 0 auto}' +
        '.tpw-dd-btn svg{flex:0 0 auto;opacity:.55;transition:transform .2s ease}' +
        '.tpw-dd-btn[aria-expanded="true"] svg{transform:rotate(180deg)}' +
        '.tpw-dd-menu{position:absolute;inset-inline-end:0;top:calc(100% + 8px);min-width:190px;max-height:min(60vh,420px);overflow:auto;' +
        'background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.16),0 2px 6px rgba(0,0,0,.08);' +
        'padding:6px;z-index:99997;opacity:0;transform:translateY(-6px) scale(.98);visibility:hidden;' +
        'transition:opacity .18s ease,transform .18s ease,visibility 0s linear .18s}' +
        '.tpw-dd.open .tpw-dd-menu{opacity:1;transform:none;visibility:visible;transition-delay:0s}' +
        '.tpw-dd-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;color:#1f2328;text-decoration:none;white-space:nowrap}' +
        '.tpw-dd-item:hover,.tpw-dd-item:focus-visible{background:#f3f4f6;text-decoration:none}' +
        '.tpw-dd-item:focus-visible{outline:2px solid #2563eb;outline-offset:-2px}' +
        '.tpw-dd-item .tpw-ddf{width:20px;height:15px;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12);flex:0 0 auto}' +
        '.tpw-dd-item .tpw-ddn{flex:1 1 auto}' +
        '.tpw-dd-item[aria-current="true"]{font-weight:700}' +
        '.tpw-dd-item .tpw-ddc{flex:0 0 auto;color:#2563eb;font-weight:700;visibility:hidden}' +
        '.tpw-dd-item[aria-current="true"] .tpw-ddc{visibility:visible}' +
        '@media (prefers-reduced-motion:reduce){.tpw-dd-menu,.tpw-dd-btn svg{transition:none}}';
    document.head.appendChild(css);

    var CHEV = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.5 6l4.5 4.5L12.5 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function flagClass(code) { return 'tpw-ddf trf trf-' + (code === 'en' ? 'us' : code); }

    for (var w2 = 0; w2 < widgets.length; w2++) {
        (function (widget) {
            if (widget.querySelector('.tpw-dd')) return;
            var wrap = document.createElement('div');
            wrap.className = 'tpw-dd no_translate';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tpw-dd-btn';
            btn.setAttribute('aria-expanded', 'false');
            btn.innerHTML = '<span class="' + flagClass(current) + '" aria-hidden="true"></span><span class="tpw-ddl"></span>' + CHEV;
            btn.querySelector('.tpw-ddl').textContent = NAMES[current] || current.toUpperCase();
            var menu = document.createElement('div');
            menu.className = 'tpw-dd-menu';
            var html = '';
            // Langue courante en premier, puis ordre du widget
            var ordered = codes.slice().sort(function (a, b) {
                if (a === current) return -1;
                if (b === current) return 1;
                return 0;
            });
            for (var k = 0; k < ordered.length; k++) {
                var c = ordered[k];
                html += '<a class="tpw-dd-item" lang="' + c + '" hreflang="' + c + '" href="' + avail[c].replace(/"/g, '&quot;') + '"' +
                    (c === current ? ' aria-current="true"' : '') + '>' +
                    '<span class="' + flagClass(c) + '" aria-hidden="true"></span>' +
                    '<span class="tpw-ddn">' + NAMES[c] + '</span>' +
                    '<span class="tpw-ddc" aria-hidden="true">✓</span></a>';
            }
            menu.innerHTML = html;
            btn.setAttribute('aria-label', (BTN_LABEL[current] || 'Language: ') + (NAMES[current] || current));
            wrap.appendChild(btn);
            wrap.appendChild(menu);
            // Masquer la rangee drapeaux d'origine (gardee en DOM pour le fallback et le SEO)
            widget.style.display = 'none';
            var holder = document.createElement('div');
            holder.style.display = 'inline-block';
            holder.appendChild(wrap);
            widget.parentNode.insertBefore(holder, widget);

            function open() {
                // L'utilisateur interagit volontairement : la bulle de suggestion devient inutile
                var sug = document.querySelector('.tpw-suggest');
                if (sug) sug.remove();
                wrap.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
                var first = menu.querySelector('.tpw-dd-item');
                if (first) first.focus();
            }
            function close(back) {
                wrap.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
                if (back) btn.focus();
            }
            btn.addEventListener('click', function () {
                if (wrap.classList.contains('open')) { close(true); } else { open(); }
            });
            document.addEventListener('click', function (e) {
                if (wrap.classList.contains('open') && !wrap.contains(e.target)) close(false);
            });
            document.addEventListener('keydown', function (e) {
                if (!wrap.classList.contains('open')) return;
                if (e.key === 'Escape') { close(true); return; }
                var items = [].slice.call(menu.querySelectorAll('.tpw-dd-item'));
                var idx = items.indexOf(document.activeElement);
                if (e.key === 'ArrowDown') { e.preventDefault(); items[(idx + 1) % items.length].focus(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); items[(idx - 1 + items.length) % items.length].focus(); }
            });
        })(widgets[w2]);
    }
})();
