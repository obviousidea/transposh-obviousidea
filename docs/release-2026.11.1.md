## Multilingual SEOPress sitemaps

- Add a distinct sitemap entry for each enabled, publicly viewable Transposh language in SEOPress post and page sitemaps. Preserve image entries and modification dates.
- Respect SEOPress exclusions, avoid expanding foreign or already localized URLs, and size sitemap pages for the expanded URL count.
- Suppress the duplicate Posts page archive entry when SEOPress already includes that page in its page sitemap.
- Use the `transposh_seopress_sitemaps_enabled` filter to opt out. Existing WP Rocket integration is unchanged.

This is the first release using `YYYY.MM.build` versioning. WordPress sees `2026.11.1` as an upgrade from `1.0.15`.

The SEOPress adapter previously ran on the ObviousIdea mirror under the 1.0.15 label. The public 1.0.15 ZIP predates that adapter. This release brings the adapter into the fork's tracked source and installable package. LiteSpeed remains a separate experiment.
