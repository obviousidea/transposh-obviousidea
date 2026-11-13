# SEO head phrases in "Translate all" (candidate 2026.11.6)

Status: branch `feat/seo-meta-phrases-2026.11.6` (local, not pushed). **Stable 2026.11.6 installed on www2 only** (13 November 2026), see [release notes](release-notes-2026.11.6.md). Production untouched. The sections below keep the rc history that led to it.

## Root cause found during www2 validation (pre-existing bug, wider than SEO)
The page render calls `change_parsing_rules(!parser_dont_break_puncts, !parser_dont_break_numbers,
!parser_dont_break_entities)`. "Translate all" and post-publish (`get_post_phrases()`) built a
default `tp_parser`, which always breaks on punctuation and numbers. With these site options
enabled (as on www2), the queued keys differed from the keys looked up at render.
- Proof on www2 (admin-only `?probe=` diagnostic): the stored key is the whole title
  `LrToolbox - restore photos from Lightroom catalog - remove 0 star` (8 languages). Translate-all
  would have queued `restore photos from Lightroom catalog`, `remove`, `star` instead.
  Those translations would have been written but never displayed.
- This affects **content as well as head phrases**, and has done so since the options were enabled.
  It partly explains why pages stay untranslated after a "Translate all".
- Fix (rc3): content and head collection apply the render's rules (`parsing_rules()`).
  The parity test runs with both rule sets.

## www2 validation (rc1 → rc3)
- Installed through the mirror-bounded installer snippet 115 (host/path lock, SHA-256 check,
  PHP token parse, 356 files equal to the build manifest). The snippet was deactivated after each install.
  The 2026.11.5 backup is kept in the server option and as a local ZIP for restore.
- LrToolbox EN + 6 languages: HTTP 200, `lang`, title, description, robots (noindex), 10 hreflang,
  canonical, no Set-Cookie and no redirect. These are **identical before and after** installation (12/12 checks).
- Collector URL `?tp_head_phrases=1`: HTTP 200, no redirect, not page-cached (no WP Rocket marker
  on repeat). Anonymous request to the coverage route: refused. Private post: `head_status=not_public`.
- Coverage of page 5723 in 1.4 s. FR after rc3: content 42/45 translated (the 3 missing are the
  feed/excerpt variant and an emoji); head 5/13, where the missing items are **exactly the visible English meta
  description** plus link titles (Feed, oEmbed, RSD) and one URL. This matches what the FR page shows.
- Not yet done: running "Translate all" itself (needs an admin browser session). Expected result: the
  description then reaches 0 missing in 6 languages.

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
