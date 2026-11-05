# Experimental LiteSpeed Cache integration

- Tag translated HTML by language so a saved translation can invalidate every affected page, including pages that share a phrase.
- Use the common Transposh tag when the affected language is unknown. Purge Transposh page tags through the LiteSpeed API and leave object cache, OPcache and CDN caches alone.
- Request a cache bypass for the translation editor and preserve the script order needed for translation startup. Also exclude the editor query parameter (normally `tpedit`) from LiteSpeed page caching so an existing cache entry cannot bypass PHP.
- The existing WP Rocket adapter is unchanged.

## Validation

PHP syntax checks passed. The adapter passed 26 standalone checks. Six additional checks exercised the unmodified Tag and Purge classes from LiteSpeed Cache 7.9.2 with a simulated WordPress environment, including site and blog tag scope.

Before a stable release, verify MISS/HIT and language isolation on a real LiteSpeed cache engine, confirm that one correction invalidates two pages sharing the same phrase while preserving other languages, and test editor bypass, automatic translation, and delayed purge delivery. The current checks establish the PHP API contract. Live cache behavior and performance remain unmeasured.
