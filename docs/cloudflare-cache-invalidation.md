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
