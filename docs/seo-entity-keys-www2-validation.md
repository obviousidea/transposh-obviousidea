# www2 integration validation — SEO entity keys

Date: 11 November 2026. Candidate: **2026.11.4-rc1**.
Repository: supermimai/transposh-obviousidea, branch `fix/seo-phrase-entity-keys`.

The candidate was installed on **www2.obviousidea.com only**. No production
request, deployment, purge, configuration change or email was part of this run.
No release or tag was published.

## Package and environment

- Previous mirror version: 2026.11.3, backed up before replacement.
- Installed candidate ZIP SHA-256:
  `5124abfbbae4f9ec4d987b27ecff764188dc5c355d4d1aeb6baa0286911032b5`.
- All **356 installed file hashes** match the build manifest; **29 PHP files**
  parsed before installation. Runtime and plugin metadata both report 2026.11.4-rc1.
- The final ZIP corrects only readme.txt after the functional campaign. Its
  other 355 files are byte-identical to the tested ZIP. It was redeployed after
  another backup, all 356 hashes and 29 PHP parses were rechecked, and three real
  translated pages passed a final metadata smoke check.
- WordPress 7.1.4, PHP web 8.3.36, WP Rocket 3.24.
- Transposh settings hash is unchanged. The mirror's existing Cloudflare
  shared-zone guard remained active and unchanged throughout.
- Temporary admin-only tooling enforced both the exact www2 hostname and the
  mirror filesystem root. HTTP clients rejected redirects outside www2.

## Real pages: SEO output

Light Image Resizer, Downloads and PhotoLikr were checked in EN/FR/DE/ES/IT/PT/PL.
Each URL was fetched twice anonymously and twice with
`cookie_notice_accepted=true`: **84 responses, 1,008 passing checks**.

The checks cover HTTP 200, no redirects, expected HTML language, self canonical,
title/description and Dublin Core metadata, preserved noindex, no PHP session,
unchanged hreflang output and no synthetic fixture text on real pages.
English source metadata remains unchanged.

Six download titles were saved using ordinary browser-decoded tokens, without
the exact-rendering alias workaround required by 2026.11.3. The existing FR/DE
download descriptions were preserved; missing ES/IT/PT/PL descriptions were saved.

The mirror's current PhotoLikr English description differs from the saved
production audit reference. Its actual current source was translated in the six
languages and verified publicly. Translation records are site content; they are
not bundled in the plugin ZIP.

The mirror does not emit Open Graph or Twitter tags on these pages. Their
rendering is covered by the isolated real-parser regression, not claimed as a
live SEOPress social-settings integration test. No social settings were enabled.

## Real WP Rocket invalidation

Two disposable noindex pages shared an apostrophe-containing title/body phrase.
They were warmed in seven languages, including both consent-cookie variants.
The warm-up preserved **56 public responses / 672 checks** and direct inspection
found **56 actual cache files** across the fourteen language/page combinations.

For each translated language, one normal human correction was made while
retaining the plugin's native translation-change and page-cache hooks.

| Language | Shared pages | Cache files before | Immediately after correction | Other language files unchanged | Identical save |
|---|---:|---:|---:|---|---|
| French | 2 | 8 | 0 | Yes | No purge / no history write |
| German | 2 | 8 | 0 | Yes | No purge / no history write |
| Spanish | 2 | 8 | 0 | Yes | No purge / no history write |
| Italian | 2 | 8 | 0 | Yes | No purge / no history write |
| Portuguese | 2 | 8 | 0 | Yes | No purge / no history write |
| Polish | 2 | 8 | 0 | Yes | No purge / no history write |

Each correction advanced the native purge sequence once and recorded exactly
the edited language. Both pages were fetched twice in both cookie variants and
rebuilt with the new title: **48 responses / 576 passing checks** in the completed
correction series. Title changes were visible in the HTML title, Dublin Core and
body, without source-language leakage.

Real database history and human alternative lookup passed for all six languages,
including a separate literal-entity token. Unchanged saves preserved the history
row count and purge sequence.

An earlier bounded series stopped when a no-op changed a whole-file hash despite
unchanged purge/history counters. Whole-file bytes can be rewritten during
asynchronous Rocket optimization, so they are not by themselves proof of a
Transposh purge. Those observations were retained. The completed repeated series
also retained all file snapshots; no such rewrite occurred in that final series.

## Existing entity segmentation

The mirror has punctuation and number splitting disabled, but HTML-entity
splitting enabled (`parser_dont_break_entities=false`). A quoted whole description
or a literal-entity paragraph can therefore consist of several dictionary
phrases. The fixture checks preserve that existing behavior rather than silently
changing global parsing settings to make a whole-entry translation apply.

The quoted fixture description remained unchanged because its full entry did
not match those phrase units. Its original literal entity remained literal;
an older Italian translation of the preceding brand fragment was observed and
left untouched. This is a separate dictionary/segmentation issue, not an
introduced key-normalization regression. Do not interpret the fixture as proof
that arbitrary quoted whole descriptions override existing segmentation.

## Isolated regressions and Cloudflare boundary

- **193/193 PHP 8.3.36 assertions pass**. The same suite against the original
  database class from master passes 105 and fails 88.
- Existing cache, parser-fragment and no-op fixtures are included.
- The existing Cloudflare contract suite passes **10 checks**, without network.
- All public test HTML returned Cloudflare **DYNAMIC**. Actual on-disk WP Rocket
  files were inspected; Cloudflare edge HTML caching was not inferred.
- **No live Cloudflare purge was attempted or validated.** The zone is shared
  with production, and its www2 guard stayed active. This PR does not change the
  optional Cloudflare adapter.

## Cleanup and rollout

Both temporary pages were trashed. Their fourteen language URLs return 404.
All synthetic translation and history rows were removed. Temporary helpers are
inactive and their routes return 404.

The final installed plugin still matches all 356 manifest hashes. The mirror's
three sitemap files remain valid and preserve the exact **1,179-URL set**, with
no duplicates or off-mirror page URLs.

Before a production rollout, review the six canonical/alias title companions
from the earlier production data repair and keep the chosen human wording. No
automatic database-wide merge is included because double-encoded text may be
intentional literal content. Production remains on its existing version until
a separate deployment is authorized.
