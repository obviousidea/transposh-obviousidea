# Transposh – ObviousIdea fork

A fork of [Transposh](https://github.com/oferwald/transposh), the WordPress translation plugin that combines
automatic translation with in-context human correction. Used in production on
[obviousidea.com](https://www.obviousidea.com/) and [lightintheeye.com](https://lightintheeye.com/en/).

Current version: **2026.11.6**. Tested with WordPress 7.1.4 and PHP 8.3.

## What this fork changes

- **Translation engines**: Microsoft Translator through the official API (key, region, endpoint; key can live in `wp-config.php`
  as `TRANSPOSH_MSN_KEY`), Ollama-assisted review with a connectivity check, and a *Protected Terms* list that engines must keep as written.
- **SEO**: absolute `hreflang` including `x-default`, languages without translations are `noindex` and left out of hreflang sets and sitemaps,
  translated URLs in SEOPress sitemaps, Open Graph locales that follow the page language.
- **Cache**: WP Rocket pages are cached per language and only the corrected language is purged; optional Cloudflare purge of configured URLs.
- **Translate all**: queues the phrases the page really looks up, including the SEO head (title, meta description, Open Graph, JSON-LD).
- **Admin REST routes** (administrators only): translation coverage per post and language, server-side fill of missing phrases, storing reviewed translations as human translations.
- **Hardening**: remote backup, restore and update services of the upstream plugin are disabled, XSS and CSRF fixes in the editor, HTTPS-only engine endpoints.

Every version is described in [CHANGELOG.md](CHANGELOG.md) and, for recent ones, in [docs/releases](docs/releases).

## Install

1. Download `transposh-obviousidea-<version>.zip` from the [Releases](https://github.com/obviousidea/transposh-obviousidea/releases) page.
   Do not use GitHub's automatic "Source code" archive: it is not an installable plugin.
2. Back up the installed plugin and the translation table in the database.
3. In WordPress, go to **Plugins > Add New > Upload Plugin** and replace the existing Transposh. Settings and translations are kept.
4. Check the pages of each language and the **Translation Engines** tab.

A fresh install is configured as upstream: activate the plugin, add the widget, choose your languages on the **Languages** tab.

## Build and test

```sh
npm ci --ignore-scripts
python build/package.py --release      # builds build/artifacts/transposh-obviousidea-<version>.zip locally
php test/head-phrases.php              # isolated: no WordPress, network or database
php test/seo-entity-keys.php
```

The other suites in `test/` run inside WordPress, for example `wp eval-file test/cache-regression.php`.
Validation reports of past releases are in [docs/validation](docs/validation).

## Documentation

See [docs/README.md](docs/README.md) for the index: release notes, Cloudflare cache purge, SEO phrase keys and validation reports.

## Credits and license

Credits are in [CREDITS.md](CREDITS.md). Transposh is licensed under the GPL version 2 or higher, as upstream.
Upstream project and general FAQ: [transposh.org](https://transposh.org/faq).
