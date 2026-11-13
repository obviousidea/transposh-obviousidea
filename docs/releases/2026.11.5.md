# Transposh ObviousIdea 2026.11.5

## Changes
- Existing primary Open Graph locale tags now follow the translated page language using Transposh's existing language registry, per-site overrides and `tp_language_locale` filter.
- Standard locale values are normalized to `ll_CC`. Unknown, malformed or script-qualified values preserve the existing tag; absent tags are not created.
- Alternate locale identifiers remain unchanged and are excluded from dictionary translation.

This release does not change language detection, automatic redirects, global settings or schema generation. JSON-LD description/headline dictionary translation already existed and was validated with actual SEOPress output. Site dictionary translations and mirror-specific adapters are not included.

## Validation
The 2026.11.5-rc1 candidate was tested only on the authorized www2 mirror:
- Actual SEOPress-generated Open Graph metadata and a temporary manual TechArticle JSON-LD fixture, with the mirror adapter disabled during integration.
- English plus French, German, Spanish, Italian, Portuguese and Polish: correct locales; JSON-LD graphs, descriptions, titles, canonical URLs and hreflang preserved.
- 31 new parser checks and 24 existing parser regressions passed, with 74 retained anonymous HTTP 200 responses across both consent-cookie variants.
- A French correction removed only the four French WP Rocket cache files. All six other language directories retained identical filenames, sizes and SHA-256 hashes. French restoration purged once, an identical save did not purge, and French files rebuilt.
- All 356 installed candidate files matched their package hashes; 29 PHP files passed syntax parsing. Temporary SEOPress settings and schema metadata were restored, and test helpers disabled.

The stable ZIP contains the tested implementation with stable version identifiers, release dates and changelog changes. Package comparison against the tested candidate is recorded in the release validation artifact. The stable package has not been deployed to production.

## Installation
Download `transposh-obviousidea-2026.11.5.zip` from this release (not GitHub's automatic Source code ZIP). Back up the installed plugin and database, then use WordPress Plugins > Add New > Upload Plugin to replace the existing Transposh plugin. Preserve existing settings and translations. Installing this release does not enable SEOPress social/schema modules or add missing translations.

## Limits
No measured speed improvement is claimed. Cloudflare HTML was DYNAMIC and the shared-zone API guard remained enabled; a real Cloudflare edge purge was not tested. This release does not address unrelated schema warnings or migrate dictionary content.

Detailed mirror validation: [SEOPress and cache test report](https://github.com/supermimai/transposh-obviousidea/blob/v2026.11.5/docs/seo-locales-www2-validation.md).

Production was not accessed or modified during release preparation.
