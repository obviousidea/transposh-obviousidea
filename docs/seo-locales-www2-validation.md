# 2026.11.5-rc1: generic Open Graph locale correction

## Change
Translated pages could retain the source locale (for example `en_US`) even when their title, description and body were French or German. The parser now updates existing primary `og:locale` tags using the existing language registry, per-site locale overrides and `tp_language_locale` filter. Standard language/territory values are normalized to `ll_CC`; unknown, malformed or script-qualified values preserve the original tag. No locale tag is created when none exists.

`og:locale:alternate` is now excluded from dictionary translation. It describes other language pages and is preserved. This implementation contains no domain, page, SEO-provider or product-specific mapping; it adds no detection, redirection, options, HTTP request or database migration.

JSON-LD description/headline dictionary translation already exists in the fork. This candidate does not replace that path, create commercial schema, or ship site dictionary translations. Missing translations retain source text. It does not automatically enable SEOPress metadata or schema.

## Real SEOPress validation (12 November 2026)
Tests ran exclusively on the authorized mirror. Its social module was off and its schema enable setting absent. Both were temporarily enabled after backup; a manual SEOPress TechArticle schema on Light Image Resizer used an English description with six existing dictionary translations. The pre-existing mirror adapter was disabled throughout the integration test, so SEOPress itself emitted the Open Graph tags and JSON-LD. The temporary TechArticle was an integration fixture, not a proposed product schema.

| Output | Before candidate | Candidate |
| --- | --- | --- |
| EN primary locale | en_US | en_US |
| FR | en_US | fr_FR |
| DE | en_US | de_DE |
| ES | en_US | es_ES |
| IT | en_US | it_IT |
| PT | en_US | pt_PT |
| PL | en_US | pl_PL |
| JSON-LD descriptions in six languages | translated | same translations |
| JSON-LD graph, titles, meta descriptions, canonicals, hreflang | reference | unchanged |

The final campaign retained 74 anonymous HTTP 200 responses: 14 baseline, 28 candidate, four French correction trials, 14 after dictionary restoration, and 14 after test-setting cleanup. Both consent-cookie variants were exercised. No redirects or PHP sessions were observed; mirror noindex remained present.

The 31 new WordPress-runtime parser assertions and 24 existing parser regressions passed. Coverage includes eight language/region variants, existing alternate locales even when a dictionary entry attempts to translate them, missing tags, unknown/invalid locales, site overrides, filter normalization, JSON-LD graph identity, invalid JSON preservation and ordinary body rendering. The older parser failed the original baseline locale tests. These are parser-runtime checks, not a claim that the entire plugin test suite or Google Rich Results validation was run.

## WP Rocket language isolation
Cache files were read in a fresh authenticated request after shutdown invalidation. Initially all seven language directories had four files. A French description correction advanced the native purge sequence once and removed only the four French files. All six other language directories retained exactly the same filenames, byte sizes and SHA-256 hashes throughout correction, restoration and warming. All four trial HTTP responses showed the edited French description inside the real SEOPress JSON-LD.

Restoring the French text caused one further French-only purge. Saving the identical six translations again caused no purge. French files rebuilt to four. Transposh's global settings hash remained identical.

Cloudflare HTML was DYNAMIC and the mirror shared-zone API guard stayed enabled. This test proves origin WP Rocket invalidation for these language/page directories, not a real Cloudflare edge purge or every page on the site.

## Installation and cleanup
The complete previous mirror plugin was backed up locally before replacement. The installed final candidate matches all 356 packaged file hashes; all 29 PHP files passed the installer's TOKEN_PARSE syntax check. Header and runtime versions both report 2026.11.5-rc1.

SEOPress toggles, the schema enable setting and the original page schema metadata were restored. The original mirror adapter was restored to its pre-test active state; it was not part of the integration proof. All temporary diagnostic/installer snippets were disabled and the temporary server package backup option removed. The candidate remains installed on the mirror for review. Production was not accessed or changed. No release, merge or production deployment is authorized by this PR.

Local evidence is retained under `generic-seo-www2-2026-11-12`: validation.json, baseline/candidate/restored/cleanup HTTP and HTML, tests-candidate.json, parser-regressions.json, cache snapshots, cleanup-validation.json and the package manifest. Credentials, site backups and test helpers are not committed.

## Running the new assertions
Under a disposable WordPress instance with Transposh loaded, require `test/seo-locales.php` and call `transposh_test_seo_locales()`. Assert that every returned boolean is true. The test temporarily installs request-local filters, removes them before return, and performs no database writes or external HTTP requests.

## Remaining scope
This is a compatibility/SEO correctness fix, not a demonstrated speed improvement. It does not migrate dictionary content, change language detection, resolve existing schema warnings, or enable SEO output on a site. Script-qualified locales are conservatively preserved pending a documented mapping policy. Review and merge separately; publish a stable release only after approval.
