# Transposh ObviousIdea 2026.11.4

SEO dictionary-key normalization, preserving the WP Rocket and SEOPress integrations from 2026.11.3.

## What changed

A title or description containing an apostrophe could stay English despite an existing translation. Parser HTML and browser editor tokens used different encodings. This release normalizes those inputs consistently for lookups, batched prefetch, edits, history and human alternatives.

Literal entity text remains distinct; normalization is not recursive. There is no automatic database-wide merge. Identical saves still avoid database/history changes and cache invalidation. A real correction invalidates the affected language, including shared phrases on multiple pages and consent-cookie variants.

## Validation

- 193 isolated assertions passed on PHP 8.3.36; the previous implementation fails 88 of the same assertions.
- Candidate installed on www2 with all 356 file hashes verified and 29 PHP files parsed.
- Downloads, PhotoLikr and Light Image Resizer in EN/FR/DE/ES/IT/PT/PL, with and without consent: 84 responses and 1,008 checks passed.
- Two shared-phrase pages per translated language: 8 physical WP Rocket cache files became 0 after each correction; both pages regenerated correctly, other languages remained unchanged, and identical saves caused no purge/history entry.
- Real database history and human alternatives verified in all six translated languages.
- Exact 1,179-URL mirror sitemap set preserved. Temporary fixtures removed and helpers disabled.
- 10 Cloudflare contract checks passed offline. No live Cloudflare purge is claimed for this campaign.

## Installation and migration

Download the installable ZIP asset, not GitHub's automatic source archive. Back up the currently installed plugin and relevant translation rows/history before replacement. Verify the ZIP against SHA256SUMS.txt.

Translations live in the site's database and are not part of the ZIP. Where an earlier workaround created both a canonical phrase and an encoded alias, retain the latest chosen human wording in the canonical record before rollout. Preserve alias records/history; do not merge all entity-looking keys automatically.

Language detection, redirects, WP Rocket settings, Cloudflare rules and sitemap configuration are unchanged by this release. The integration report documents the mirror's entity-splitting behavior and absent social tags. Shop-specific asynchronous prices are not included.

See [technical explanation](seo-entity-keys.md) and [www2 integration evidence](seo-entity-keys-www2-validation.md). This release does not by itself confirm installation or validation on production.
