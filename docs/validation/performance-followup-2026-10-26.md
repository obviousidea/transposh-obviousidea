# Follow-up: plugin-only performance corrections validated on www2

## Scope

The following changes are internal to Transposh. They need no commercial
shortcode or theme changes, no new dependency, no database migration and no
change to the selected language policy. They were installed on the www2 mirror
on 26 October 2026; production was not modified. The existing published rc1 ZIP
is unchanged. This is candidate-branch work, not a new release.

This is separate from the [asynchronous shop prototype](shop-fragments-2026-10-26.md).
Its large initial-display improvement requires the site's commercial
integration. Installing these plugin changes alone does not convert a shop to
private asynchronous prices.

## Changes

1. **Only prefetch URL segments when URL translation is enabled.** The page
   parser previously looked up path segments even with `enable_url_translate`
   off. The callback is now conditional in `process_page`; the public
   `split_url` API remains unchanged. Visible phrases still receive normal
   dictionary prefetch.
2. **Do not re-encode an already stored translation during no-op detection.**
   The old comparison HTML-encoded and URL-decoded the fetched stored value
   again. Identical saves containing ampersands, apostrophes, quotation marks,
   entities, literal percent sequences or markup could therefore write and
   invalidate again. Compare the incoming normalized SQL value to the stored
   HTML-encoded value escaped for SQL once, without decoding it as transport.
   Real text changes and automatic-to-human source changes still invalidate the
   affected language. Shared-phrase invalidation is preserved.
3. **Complete empty-container parser handling.** Preserve a non-empty
   `added_header` when appending statistics to a head without child elements,
   and guard an empty buffer before inspecting its first JSON character.

The automatic-translation purge policy, session/redirect behavior, User-Agent
handling, SQL indexes and persistent negative caching were not changed. Those
suggestions require separate policy or profiling checks.

## Tests on the mirror

The installed runtime sources were first backed up and compared with the
reconciled rc1 source using PHP tokens, excluding comments and whitespace. All
three matched. Only `transposh.php`, `core/parser.php` and
`wp/transposh_db.php` were replaced, with syntax checks, expected-hash guards,
atomic replacement and targeted OPcache invalidation for the mirror paths.

**82 plugin assertions passed**: 46 focused parser/write-path/routing assertions,
25 existing cache regressions, and 11 translation compatibility assertions.
The installed classes were retested after replacement.

- A synthetic translated document with twenty product links prefetched **44
  keys before and 2 afterward** with URL translation off. Full rendered HTML
  matched in EN/FR/DE. With URL translation on, the same 44-key behavior was
  retained. This is a fixture count, not a count measured on every live page.
- Six special-character no-op cases reproduced **three SQL writes and one
  invalidation event** in the installed old method. The candidate and then the
  newly installed method produced zero writes and zero invalidation events.
  The ordinary-text control also remained a no-op.
- Real text changes and source promotion still produced the expected writes
  and language-scoped change notification.
- Write-path tests used SQL and action spies: **no real translation row was
  written or deleted, no real page purge was triggered by the mock writes,
  and no translation backup or email was sent**. They validate the method's
  decisions, not database storage under a live editor session.
- Empty heads, whitespace/comment-only heads, an additional header, a text-only
  body with editing spans, empty JSON input, headless fragments, RTL, normal
  output, scripts and styles were checked. Appending hidden editing spans also
  preserves pre-existing text in a body without child elements.

The temporary administrator-only validation helper was deactivated afterward.
The corrections do not call or depend on it.

To repeat focused tests in a WordPress test environment with Transposh loaded:

```sh
wp eval-file test/parser-fragments.php
wp eval-file test/noop-translations.php
wp eval-file test/prefetch-routing.php
wp eval-file test/cache-regression.php
wp eval-file test/translation-compatibility.php
```

## Public Light Image Resizer checks

EN/FR/DE were tested before and after installation with anonymous public GETs,
fresh connections, identical headers and no cache-busting query. Each phase
contained three rounds. Before a round, only these three mirror URLs were
passed to WP Rocket's file-cleaning API. The first response after that targeted
clear was retained separately, followed by four interleaved repeats per URL.
There were **90 responses total**, all HTTP 200 with expected language and
noindex. Visible content and content-link hashes matched before and after.

| Language | First-after-clear median before → after | Warm median before → after |
| --- | ---: | ---: |
| English | 932 → 896 ms | 167 → 168 ms |
| French | 1,006 → 960 ms | 169 → 161 ms |
| German | 1,017 → 989 ms | 174 → 159 ms |

These small timing differences do **not** establish a new causal percentage
improvement. There are only three first-after-clear observations per language,
the German first series contains outliers above two seconds in both phases,
and one English first-after-clear response in each phase was already fast.
Preloading or other requests may repopulate a cache; “first after clear” is
therefore not proof of an entirely cold execution. Dictionary and global
OPcache caches were not reset.

The demonstrated benefits are less unnecessary work and avoidance of false
write/purge decisions. Warm HTML already served by WP Rocket should not gain a
new parser-speed benefit. No Google or Cloudflare real-user result is claimed.

## Deployment boundary

For sites with compatible WordPress, PHP and cache settings, these generic
changes can be delivered by updating the Transposh plugin alone. Mirror success
reduces risk; it does not guarantee identical production behavior. A production
rollout still needs the correct candidate archive, rollback availability and
post-update checks for translations, cache and first-visit redirects.

No migration of the shop prototype, helper activation, OPcache capacity change,
redirect-policy change or release publication is implied by this validation.
