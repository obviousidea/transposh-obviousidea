# WP Rocket integration candidate — 15 October 2026

This is a development candidate for `supermimai/transposh-obviousidea`.
Validation was performed on **www2.obviousidea.com only**, with WordPress 7.1.3,
PHP 8.3.33 (Apache) and WP Rocket 3.23.5.1. No production deployment, shared
OPcache reset or Cloudflare-zone change was part of validation. The original
validated source snapshot displayed 1.0.14. The subsequent 1.0.15-rc1 package
changes version metadata and release documentation, with the same runtime
implementation. It is published separately as a GitHub pre-release; the stable
readme tag stays 1.0.14 and production is unchanged.

## Source reconciliation

The installed mirror contained changes not present in repository base
`91e6d90e8199c4a0eb117920917e008f93cb8530`, despite the README describing them.
The candidate includes the installed Ollama provider, its language registration,
options, administration/connectivity UI and editor suggestion button. It also
includes protected terms for Microsoft/Ollama, complete reciprocal hreflang
output with x-default, and safe escaping of scalar suggestion results.

The repository's newer Bing source-language fallback is retained: use the site's
default language instead of assuming English. Repository version/build metadata
is retained. Installed `.BAK.php` copies and obsolete generated assets are not
source inputs. The candidate package is built from the reconciled source tree,
not from an overlay of four files onto an unknown installation.

## Cache behavior

- Canonical language-prefixed singular URLs can pass Rocket's permalink check
  without changing language-specific cache keys. Unknown query parameters and
  aliases remain excluded; tracking parameters follow Rocket's own allowlist.
- Editing disables page-cache generation and HTML optimization.
- Actual dictionary writes emit `transposh_translations_changed` with the
  changed languages. An empty/unknown scope means all current-site languages.
- Changes are grouped until shutdown, after database writes. Every cached page
  in each changed language is invalidated, including shared text and consent
  variants. Other languages are preserved. An identical save emits no purge.
- Rocket's normal purge API is used first. A guarded fallback handles its
  once-per-request limit if files were regenerated after an earlier purge.
- Dictionary keys include a site namespace and epoch, hash both language and
  original text, and expire within 24 hours. Clearing a dictionary never flushes
  a shared Memcached/Redis/APCu service.
- Request-local prefetch accepts the parser's `phrase => true` map as well as
  lists. Memcached uses batches of at most 250 keys. Escaped phrases and actual
  SQL misses are memoized per language; failed SQL reads remain retryable.
- An explicit language URL takes precedence over remembered/detected language.
  Merely displaying the remember-language widget does not start a PHP session.

`transposh_page_processed` is an optional profiling action. If a listener is
registered, it receives language, processing duration, SQL count and output size.
It emits no public HTML or diagnostic endpoint.

## Completed mirror tests

The final package-validation cycle has 106 passing assertions. The earlier
four-file prototype had a separately reported 116-check inventory; the counts
describe different suites, not a measured performance change.

- A complete 354-file ZIP was parsed (27 PHP files), installed using WordPress's
  Plugin_Upgrader, then activated in a separate request.
- Package rollback restored all 381 pre-update files with identical SHA-256
  hashes; an uncached French reference page returned HTTP 200 afterward. The
  candidate was reinstalled and activated. Its 354 installed hashes match the
  package manifest. This rehearses plugin rollback with the existing Rocket
  configuration; it does not claim a production rollback or an engine migration.
- The actual authenticated translation editor saved and restored a temporary
  French correction of `Features`. Two separate reference pages displayed it.
  All existing French cache files were removed in one purge; English and German
  files were unchanged. The original French translation was restored.
- Microsoft Translator automatically translated a disposable fixture phrase and
  stored source 2. The real proxy returned that cached result on a repeat request
  without an additional purge. A real scalar Microsoft suggestion returned valid
  JSON. No live Ollama model endpoint was exercised; response-format and
  protected-term compatibility checks use local synthetic values.
- Nine active languages passed anonymous HTTP checks at cold/warm cache,
  including conflicting browser language and preference cookie, correct HTML
  language, no unwanted redirect/session and retained noindex directives.
- Mixed FR/DE changes invalidate both consent variants while preserving English.
  Restoring originals and identical re-saves are verified.
- Dictionary/prefetch checks cover namespace/epoch isolation, key collisions,
  TTL, actual misses, escaping, lists/maps, batching, and SQL/cache failures.
  A disposable key in the real shared Memcached backend also survived a native
  dictionary clear; the key was removed afterward. Only the mirror dictionary
  generation and mirror page cache were invalidated.
- The French contact form rendered and validated required fields. A submission
  through Chrome passed the normal Cloudflare-protected flow and reported
  success. A test-marker-only mail guard suppressed delivery. Raw HTTP submissions
  without a Turnstile response were rejected as spam, as expected; the protection
  was not disabled or bypassed.
- The mirror download landing page returns HTTP 200 and retains its installer
  link. A read-only HEAD of that discovered installer URL returns HTTP 200,
  executable content type and Content-Length 46,540,888. No installer was
  downloaded, executed, replaced or republished as part of this plugin test.
- Disposable fixtures return to the trash and fixture-only dictionary/log data
  is cleaned up with recoverable backups. Temporary admin helpers are disabled
  at the end. A mirror-only guard against accidental shared Cloudflare API calls
  remains enabled outside the plugin.

## Performance interpretation

On the Light Image Resizer reference page, batching replaces 191 individual
Memcached reads with one multi-get. Translation SQL calls drop from 9 (French)
and 12 (German) to 2 each. Normalized rendered-text hashes match before/after in
English, French and German. This reduces backend work but did not demonstrate a
clear total uncached-generation time reduction in the bounded profiling series.

Warm HTML responses are around 0.15–0.18 seconds in these local measurements;
page-cache regeneration remains roughly 0.8–0.95 seconds, network included.
These are TTFB measurements, not PageSpeed scores or real-user first-paint
percentiles. Warm WP Super Cache responses were already comparable. The main
Rocket gain over its pre-integration state is making translated pages eligible
for reuse and invalidating them correctly.

The web OPcache snapshot is full at 16,229/16,229 keys with a configured
`opcache.max_accelerated_files=10000`, despite a 512 MiB allocation. About 31 MiB
is free; the separate 64 MiB interned-string buffer has about 18 MiB free.
Server-side review should address the key ceiling, then check memory headroom
after warm-up. A larger memory allocation alone does not remove the key ceiling.
No shared-server setting was changed by this candidate.

## Reproduce and review before release

Build a **local** package (no upload or release command):

```sh
npm ci --ignore-scripts
python build/package.py --build-date 2026-10-15
```

The output and SHA-256 manifest are in ignored `build/artifacts/`. Two builds
from the same source/dependencies/build date produce the same ZIP. Without
`--build-date`, the current UTC date is used. JavaScript is minified
with the locked Terser version. Runtime directories are copied in full; test and
build scripts, node_modules, installed backups and credentials are excluded.

On an authorized WordPress test instance with the plugin loaded, memory caching
enabled, and French/German active:

```sh
wp eval-file test/cache-regression.php
wp eval-file test/translation-compatibility.php
```

Those checks use fake dictionary clients/SQL or synthetic provider responses;
they do not modify real translations or call a model. They complement, rather
than replace, the browser and cache-file checks above.

Before a general release, review language-personalization policy and test any
required translated slugs, archives/pagination, RTL, multisite, PHP/WordPress/Rocket
versions and non-Memcached adapters. Those combinations are outside this mirror's
complete end-to-end coverage. Automatic browser/GeoIP redirects and remembered
default-language selection remain disabled on the mirror. Do not copy settings
or deploy to production without a separate approved migration plan.
