# Transposh ObviousIdea 2026.11.6

## Why this release
"Translate all" and the automatic translation after publishing queued the wrong phrases on sites that enable
*Disable punctuations break* / *Disable numbers break* in Advanced. The page render applies those options
(`change_parsing_rules`), but the collection used a default parser. A title stored as one phrase, for example
`LrToolbox - restore photos from Lightroom catalog - remove 0 star`, was queued as `restore photos from Lightroom catalog`,
`remove` and `star`. The translations were written but never displayed. The SEO head of a page (title, meta description,
Open Graph, JSON-LD) was not collected at all, so meta descriptions stayed in the source language.

## Changes
- Content and head collection use the render's phrase-breaking rules. The parity test runs with both rule sets.
- The rendered head is collected through an anonymous loopback request with `?tp_head_phrases=1`. The result carries a status
  (`ok`, `disabled`, `not_public`, `protected`, `no_permalink`, `http_error`, `http_<code>`, `no_head`) that the admin
  translate-all screen and the coverage route show. Filters: `transposh_collect_head_phrases`, `transposh_head_phrases_timeout`.
- New REST routes, all `manage_options` (and `edit_post` where a post is involved):
  - `GET transposh/v1/coverage/<id>`: human/auto/missing/errors per language for content and head. `?probe=<text>` shows how one phrase is stored and looked up.
  - `POST transposh/v1/translate-missing/<id>`: dry run by default. Fills phrases that have no translation through Microsoft Translator, stored like the on-page proxy (source 2). Skips URLs, phrases without letters and technical link labels (`transposh_engine_skip_phrases`). Never replaces an existing translation. With `engine_test` it only reports the HTTP status and the API error code of a one-word Microsoft Translator call, never the key.
  - `POST transposh/v1/translations`: stores reviewed translations as human translations (source 0), dry run by default, returns before/after for each item.
- Translation Engines tab: one field per row, API keys masked with a Show/Hide button, readable widths.

## Not changed
`core/parser.php`, `wp/transposh_db.php`, the page-cache module, language detection, redirects, sitemaps and global settings are
byte-identical to 2026.11.5. The 2026.11.5 parser and cache validations therefore still apply to those files.

## Validation
- `php -l` on all 43 PHP files; `test/head-phrases.php` 32/32 (isolated, also run against seven real production pages);
  `test/seo-entity-keys.php` 193/193.
- Installed on the www2 mirror only: the 356 installed files match the build manifest; the ten Transposh admin pages render without
  PHP notices; LrToolbox in seven languages kept the same status, language, robots, hreflang and canonical before and after.
- A real run on www2: 54 phrases translated with Microsoft Translator, then six reviewed descriptions and four technical labels stored as human translations.
  The reviewed descriptions are served on the public pages.

## Installation
Download `transposh-obviousidea-2026.11.6.zip` from this release (not GitHub's automatic Source code ZIP). Back up the installed
plugin and the translation table, then replace the plugin with Plugins > Add New > Upload Plugin. Settings and translations are preserved.
If your site enables the parser options above, run "Translate all" again after the update: phrases queued by earlier versions may have been stored under keys the page does not use.

## Limits
Cloudflare edge purges were not exercised. The REST fill uses Microsoft Translator only. No speed improvement is claimed.
Nothing was installed on or changed in production during release preparation.
