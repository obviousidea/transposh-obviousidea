# SEO phrase keys: quote/entity normalization (candidate)

Status: prepared on `fix/seo-phrase-entity-keys`, based on master `b0bee01`.
This is an unreleased candidate, version **2026.11.4-rc1**, installed and validated
on www2 only. No production installation or release is included.
See the [www2 integration report](seo-entity-keys-www2-validation.md).

## Problem and resulting behavior

A title such as `Official Download page for Obviousidea&#039;s Software - no ads`
could remain English even though the translation editor contained its translation.
The HTML parser retained an encoded quote; the browser decoded that quote before
posting a correction. The database layer used `html_entity_decode(..., ENT_NOQUOTES)`
followed by `htmlspecialchars()`, producing different dictionary keys:
`&#039;` from a raw apostrophe and `&amp;#039;` from the encoded input.

For parser/HTML input, the candidate decodes entities once, including quotes,
then explicitly encodes to the existing HTML401 storage representation. Editor
and history tokens are already browser-decoded: their existing ENT_NOQUOTES
handling is preserved so literal entity text is not interpreted as a real quote. Parser lookups, batched
prefetch, translation writes, history lookup/deletion and human alternatives use
the same helper in `wp/transposh_db.php`. SQL escaping remains at the call sites and cache key contracts are preserved. The parser, page-cache hooks, redirect policy and sitemap code are unchanged.

Equivalent parser/HTML apostrophe representations now address the same
dictionary entry when they form the same phrase unit. Browser-decoded editor
tokens retain their literal-text contract. Unchanged editor saves remain no-ops, machine writes
cannot replace an existing human translation, and changed entries still clear
request memory/dictionary cache and notify language invalidation.

## Compatibility and deliberate limits

- Previously stored canonical keys from ordinary editor saves remain readable.
- Decode exactly once. Literal entity text, represented as `&amp;#039;` in HTML,
  is distinct from an actual apostrophe. Do not recursively decode or globally
  merge database rows.
- Historical double-encoded keys are left intact. This patch does not choose
  between conflicting human translations or migrate/delete historical data.
- The November 11 production content repair added six exact-rendering aliases
  to work around the current plugin. Before deploying this candidate, review
  their canonical companions on www2 and save the chosen wording through the
  normal editor. Preserve the history and check both encoded/raw renderings.
  Do not treat all double-encoded rows as disposable: some may represent literal text.
- New SEO descriptions without any dictionary entry are a separate coverage
  issue; key normalization does not manufacture missing translations.
- Backups/restores and reverse-translation lookup retain their existing transport
  contracts. No backup-format migration is part of this patch.
- Changing the selected cache backend, GeoIP or language detection is unnecessary.

## Validation

Run outside WordPress:

```sh
php -l wp/transposh_db.php
php -l test/seo-entity-keys.php
php test/seo-entity-keys.php
```

PHP 8.3.36 isolated CLI:
**193/193 assertions pass**. The identical test against the unmodified database
class from master passes 105 and fails 88, reproducing the bug.

Coverage includes six target languages FR/DE/ES/IT/PT/PL; raw, decimal, hexadecimal
and named apostrophes; double quotes, ampersands, Unicode, percent text and markup;
literal entity distinction on both reads and editor writes; real parser title/description/Open Graph/Twitter
rendering; one-query batched prefetch and memoization; SQL failure retry;
write/no-op/duplicate-batch/invalidation behavior and automatic-vs-human protection.

Existing dictionary-cache, parser-fragment and no-op fixtures are also run in the
isolated bootstrap. SQL and cache backends are test doubles. The separate [www2 report](seo-entity-keys-www2-validation.md) covers real
WordPress/MySQL history and shared-phrase WP Rocket invalidation. No live
Cloudflare purge is claimed.

## Integration completed; remaining release gates

1. Completed: candidate installed on www2 after a full plugin backup.
2. Completed on www2: chosen canonical title wording saved through the normal editor.
   Still required for production: review canonical/alias companions before rollout.
3. Completed: native title/description/DC, language, canonical and unchanged hreflang
   output checked on seven languages and both consent variants. The mirror does
   not emit social tags; those remain isolated-parser coverage.
4. Completed: six-language shared-phrase invalidation, physical cache snapshots
   and identical-save no-op checks. Existing entity segmentation is documented.
5. Completed: real database history and human alternatives through the same
   backend methods used by the editor. No separate browser UI test is claimed.
6. Completed: sitemap URL-set equivalence and temporary-tool cleanup.
7. Review and merge the PR before assigning a stable YYYY.MM.build package/release.
   Do not overwrite the public 2026.11.3 ZIP or tag. Production deployment still
   requires its own authorized backup and post-installation checks.

## Draft release note

Fix SEO titles and descriptions that stayed in the source language when HTML
quote entities and browser-decoded editor tokens produced different translation
keys. Use one normalization contract across lookup, prefetch, editing and history;
preserve literal entity text and avoid recursive decoding or database-wide merges.
