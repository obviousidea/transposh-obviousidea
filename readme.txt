=== Transposh WordPress Translation ===
Contributors: oferwald
Donate link: http://transposh.org/donate/
Tags: translation, translate, i18n, widget, filter, buddypress, bilingual, multilingual, transposh, language, crowdsourcing, google translate, bing translate, context, wiki, RTL, Hebrew, Spanish, French, Russian, English, Arabic, Portuguese
Requires at least: 5.8
Tested up to: 7.1.3
Stable tag: 2026.11.2

Transposh adds best of breed translation support to wordpress, more than a 100 languages are automatically translated and can be manually corrected with ease.

== Description ==
Transposh translation filter for WordPress offers a unique approach to blog translation. It allows your blog to combine automatic translation with human translation aided by your users with an easy to use in-context interface.

== Changelog ==

= 2026.11.2 =
* hreflang and x-default now absolute URLs; languages without stored translations are noindex (meta + X-Robots-Tag), excluded from hreflang sets and from SEOPress sitemaps, with cache-aware status invalidation on every dictionary write.
* Protected terms: comma or newline lists, case-insensitive matching, enforced on every engine write, results losing a term are rejected; fixed the Advanced tab silently wiping the term list.
* Translation editor: stored XSS escaped, delete and bulk actions CSRF-protected, rows with accents no longer break history/delete, purge-dictionary-cache button added.
* Parser: attribute corruption on duplicate translations fixed; og:url and JSON-LD localized; technical meta tags no longer translated.
* Hardening: upstream backup/restore/update services disabled (no remote unserialize), proxy quota gate with editor suggest-mode passthrough, Microsoft key via wp-config constant and HTTPS-only endpoint, PHP 8.3 admin save fixes, memory-limit check corrected.

= 2026.11.1 =
* Add translated URLs to SEOPress post and page XML sitemaps.
* Avoid duplicate entries for the WordPress Posts page when it is included in the page sitemap.

= 1.0.15 =
* Add native WP Rocket support for language-prefixed pages and language-scoped invalidation after real translation changes.
* Isolate dictionary caches by site and group Memcached reads in bounded batches.
* Avoid unused URL-segment prefetch when translated URLs are disabled.
* Prevent identical special-character translation saves from causing unnecessary database writes and cache invalidations.
* Handle empty HTML containers and JSON buffers without dropping header or body content.
* Preserve explicit URL language priority and existing shared-phrase invalidation.
* Include complete installable packaging and documented mirror validation. Updating the plugin does not migrate site-specific asynchronous shop prices.

= 1.0.15-rc1 (pre-release) =
* Added native WP Rocket support for canonical language-prefixed singular pages.
* Invalidate all affected-language pages after actual translation changes, including shared phrases and consent variants.
* Isolate dictionary cache keys by site and generation without flushing shared cache services.
* Fix parser prefetch maps and group Memcached reads in bounded batches.
* Keep explicit URL languages deterministic and avoid unnecessary PHP sessions.
* Reconcile Ollama, protected terms, reciprocal hreflang and scalar suggestion handling from the test mirror.
* Add reproducible packaging and cache/provider regression checks. Validated on www2 only; no production deployment.

= 1.0.14 =
* Fixed Bing source language fallback to use the site's default language instead of always forcing English.

= 1.0.13 =
* Switched Bing translations to the official Microsoft Translator API.
* Added Ollama support for manual translation review with configurable endpoint, API key, model, and prompt template.
* Added an Ollama connectivity check in the admin interface.
* Added protected terms support to preserve brand names and product names during translation.
* Added hreflang x-default output for multilingual SEO.
* Fixed Bing manual suggestion decoding for phrase-by-phrase review.
* Fixed translation editor asset versioning to avoid stale cached scripts and styles.
