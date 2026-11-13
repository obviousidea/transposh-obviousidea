# SEO head phrases in "Translate all" (candidate 2026.11.6)

Status: branch `feat/seo-meta-phrases-2026.11.6`, local only. Not installed on www2 or production.

## Problem (measured on production, 13 November 2026)
`transposh_postpublish::get_post_phrases()` feeds the admin "Translate all" and post-publish
translation. It only collected content, title, excerpt and permalink phrases. Phrases that
only exist in the rendered `<head>` (the SEOPress title, meta description, Open Graph/Twitter
texts, JSON-LD headline/description) were never queued. Result: by language, 95 to 108 of the 126
sitemap pages keep the English meta description. Example: LrToolbox, where the body is translated
in six languages and the description stays English in all six.

## Change
- `get_rendered_head_phrases()` fetches the default-language permalink anonymously, with
  `?tp_head_phrases=1` (bypasses WP Rocket's file cache; no cookies, so no remembered-language
  redirect; redirects are not followed; 8 s timeout, filter `transposh_head_phrases_timeout`).
  It returns a status (`ok`, `disabled`, `not_public`, `protected`, `no_permalink`, `http_error`,
  `http_<code>`, `no_head`) so that a failure is never shown as empty coverage.
- `extract_head_phrases()` mirrors `fix_html()`: the same UTF-8 NBSP normalisation, the same DOM
  selector for `script[type=application/ld+json]`, the same `translate_tagging` rules for
  `<title>`, `<meta content>` and `<link title>`.
- Translate-all receives `head_status` and shows a warning when the head was not collected.
- Read-only REST `GET transposh/v1/coverage/<id>` (`manage_options` + `edit_post`). It reports
  human, auto, missing and errors per language, for content and head separately. It uses one
  batched query per language and is capped at 2,000 phrases (filter `transposh_coverage_max_phrases`).
- Filter `transposh_collect_head_phrases` (default true) disables the collection.

## Tests
`php test/head-phrases.php [--fixture=page.html]`: isolated, with no WordPress, network or database.
It checks a parity property: every phrase the parser asks a translation for while rendering the
head must also be collected. It covers literal NBSP, unquoted or varied JSON-LD attributes,
several JSON-LD scripts and invalid JSON.
Result: 21/21 standalone, and 22/22 with each of 7 real production pages as fixture (home, LrToolbox FR,
TapoPanel, LIR, Light Capture, WIC, Light Capture launch post). `test/seo-entity-keys.php`: 193/193.

## Independent review
A read-only Codex CLI review found 6 issues. All were addressed:
1. NBSP key mismatch: fixed and tested.
2. Silent loopback failures: fixed with a status and an admin warning.
3. Cache and analytics side effects plus timeout: reduced to 8 s and documented. Still to check on www2 through Cloudflare, Wordfence and WP Rocket.
4. Coverage cost and misleading metrics: fixed with batch prefetch, a cap, and `errors` reported separately from `missing`.
5. JSON-LD selector mismatch: fixed by using the parser DOM selector.
6. Title filter applied twice: fixed.

## www2 checklist before any production rollout
- Loopback through Cloudflare, Wordfence and WP Rocket: HTTP 200, default language, no WP Rocket cache file for `?tp_head_phrases=1`, no Independent Analytics hit.
- Coverage before and after "Translate all" on LrToolbox: `head.missing` falls to 0 in six languages; rendered titles and descriptions are translated.
- Draft, private and password-protected posts, plus a forced timeout: the status is reported.
- Duration of translate-all over all posts; the `-555` tags item; permalink phrases.
- Existing regressions: parser-fragments, cache-regression, noop-translations, seo-locales.
