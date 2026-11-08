# Cloudflare cache invalidation after translation changes

The integration is opt-in and runs after Transposh successfully invalidates the
WP Rocket cache. It submits a single Cloudflare file purge for the explicitly
configured paths in the affected languages. If Transposh invalidates every
language, the integration sends all configured paths. It never sends a zone,
host, prefix, or tag purge.

## Configuration

Set these constants in server-side configuration and keep the token out of
source control, WordPress option exports, and diagnostics:

- `TRANSPOSH_CLOUDFLARE_ZONE_ID` (32-character Cloudflare zone ID)
- `TRANSPOSH_CLOUDFLARE_API_TOKEN` (Cloudflare token with Cache Purge for that zone)

Register the paths with the `transposh_cloudflare_cache_paths` filter in
site-local code. Paths are relative to the WordPress home URL and must start
with the language code, contain only letters, numbers, hyphens or underscores
in their segments, and end with a slash.

```php
add_filter('transposh_cloudflare_cache_paths', static function () {
    return array(
        'fr' => array('/fr/windows-software/light-image-resizer/'),
        'de' => array('/de/windows-software/light-image-resizer/'),
    );
});
```

Use this with the default Cloudflare cache key and an anonymous, cookie-free,
query-free Cache Rule for the same URLs. When the same phrase occurs on more
than one cached page, list each applicable URL. Query strings, custom cache
keys, and translated URLs outside the configured path format are outside scope.
Keep an edge TTL as a backstop and check invalidation separately for changes
outside Transposh's dictionary, including WordPress content edits.

The integration sends at most 100 unique URLs per invalidation. It uses a
three-second timeout and records the outcome in the non-autoloaded
`transposh_cloudflare_last_purge` option, without recording credentials,
paths, or response bodies. Cloudflare accepting a purge is distinct from
observing a cache MISS. On an API or transport error, WordPress keeps the saved
translation, and the operator can purge the configured URLs manually.

## Validation on the ObviousIdea mirror

- Built and installed the complete `2026.11.3-rc1` ZIP from this branch, based on `master` at `2fa07c0`. All 356 installed files matched the package manifest. WordPress 7.1.4, PHP 8.3.36 and WP Rocket 3.24 were used.
- Passed 10 standalone Cloudflare contract checks and 86 existing assertions in the mirror's WordPress runtime.
- Light Image Resizer EN, FR and DE returned HTTP 200 in the expected language and kept the mirror's noindex protection. The post and page sitemap URL sets were unchanged (495 and 684 URLs respectively).
- Warmed FR and DE at Cloudflare, then saved a real temporary translation correction in each language. WP Rocket invalidated the corrected language first. Cloudflare accepted exactly one mirror URL for each correction (HTTP 200). The corrected text appeared on subsequent requests, and the other language remained a HIT. Re-saving identical DE text left the translation log and purge sequence unchanged.
- Removed the temporary translations and disabled the mirror's pilot cache rule and test helpers. The existing mirror boundary stayed active. No production changes or purges.
