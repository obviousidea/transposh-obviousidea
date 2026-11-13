# ObviousIdea 1.0.15 release verification — 26 October 2026

This release was verified on the public www2 mirror, with WordPress 7.1.3,
PHP 8.3.36 and WP Rocket. Production was not modified.

## The actual distribution archive

- Two builds from the same source and fixed date produced identical bytes.
- The complete installable ZIP contains 354 files under the existing plugin
  directory name. No credentials, local helper, test suite or unfinished
  concurrent LiteSpeed work is packaged.
- The ZIP was uploaded to a draft GitHub release, downloaded again and compared
  byte-for-byte with the local build.
- That downloaded archive was installed with WordPress's
  [plugin installer](https://developer.wordpress.org/reference/classes/plugin_upgrader/).
  All 27 packaged PHP files passed syntax parsing before replacement.
- After activation in a separate request, both plugin metadata and the loaded
  runtime reported 1.0.15. Every installed file matched the release manifest.
- The entire pre-release mirror plugin was restored from its backup and
  reactivated. All 354 pre-release file hashes matched the saved state.
- The same downloaded 1.0.15 ZIP was then reinstalled and reactivated. All 354
  release file hashes matched again.

SHA-256 of the tested installable ZIP:

```text
9c8ef1c4e29557601beccab779e7cb5344428b1954e2c77421a6079c09019bbf
```

The release includes `SHA256SUMS.txt`, a per-file `manifest.json` and a compact
`validation-summary.json`. Checksums provide integrity checking, not a separate
code-signing certificate.

## Regression coverage on the installed package

**82 plugin assertions passed** after installation of the full release ZIP:
46 focused parser/write-path/routing assertions, 25 cache regressions and 11
translation compatibility checks. These include the special-character no-op
cases, actual text/source changes, URL translation on/off, EN/FR/DE output
comparison, RTL, empty containers, editing spans, cache namespace isolation,
request-local misses, bounded multi-get and translation-provider parsing.

The earlier plugin-only comparison retained 90 public HTTP responses and
unchanged visible text and links. Thirty-one checks also covered compatibility
with the separate inert commercial prototype. Seven final public checks were
repeated after package installation for consent variants and the private
commercial fragment response.

## Real stored translation and shared-page invalidation

To complement SQL/action-spy tests, a disposable phrase was stored through the
real translation-update method in French and German. Two temporary noindex
pages shared it. Both pages were requested in EN/FR/DE, with and without the
consent cookie, and the existence and hashes of their actual Rocket cache files
were inspected within the mirror boundary.

Six integration checks passed:

1. Both pages, all three languages and both consent variants had cached output.
2. Saving the identical French value containing apostrophes, ampersands,
   quotation marks and a literal `%20` did not add a translation log row,
   increment the purge sequence or change the existing cache files.
3. A real French correction incremented the purge sequence once for French.
4. Both shared-phrase pages and their French consent variants were invalidated.
5. English and German cache file hashes were unchanged by that correction.
6. Both public French pages then displayed the corrected stored text; English
   and German remained correct.

The test pages were moved to Trash and the exact disposable phrase's rows and
log were deleted. Its dictionary entries were removed. Test-only notification
and remote backup side effects were suppressed within those requests; real
settings and translation-change invalidation remained intact. Existing content
and translations were not edited.

## Browser and operational checks

Fresh Chromium desktop contexts loaded LIR EN/FR/DE with HTTP 200, correct HTML
language, meaningful content and no JavaScript page errors. The French render
was inspected visually. Production/payment hosts and analytics collection were
blocked in these contexts; automatic translation writes were blocked during
these read-only browser checks. This browser check therefore does not claim to
exercise every automatic translation service.

Temporary release and regression helpers were disabled after verification.
Mirror-only backup/fixture state was cleaned up. The Cloudflare shared-zone
isolation guard stayed active. No production settings, cache purges, plugin
updates, emails or security mitigations were changed.

## Limits and rollout

This is enough evidence to publish the ObviousIdea fork release for its tested
stack, not a guarantee across every WordPress/PHP version, multisite setup,
language, browser or cache provider. The stored-translation integration test
used an authenticated test route into the real update method, not a manual
click-through of every editor control.

Keep a production backup and perform post-update checks for translations,
redirects and cache behavior. A mirror rollback is not proof of the contents of
a production backup. No additional real-user Cloudflare percentage improvement
is claimed by this release. The asynchronous shop architecture remains a
separate site integration.
