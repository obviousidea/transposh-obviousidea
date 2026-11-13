## Multilingual SEOPress sitemaps

- Add a distinct sitemap entry for each enabled, publicly viewable Transposh language in SEOPress post and page sitemaps. Preserve image entries and modification dates.
- Respect SEOPress exclusions, avoid expanding foreign or already localized URLs, and size sitemap pages for the expanded URL count.
- Suppress the duplicate Posts page archive entry when SEOPress already includes that page in its page sitemap.
- Use the `transposh_seopress_sitemaps_enabled` filter to opt out. Existing WP Rocket integration is unchanged.

This is the first release using `YYYY.MM.build` versioning. WordPress sees `2026.11.1` as an upgrade from `1.0.15`.

The SEOPress adapter previously ran on the ObviousIdea mirror under the 1.0.15 label. The public 1.0.15 ZIP predates that adapter. This release brings the adapter into the fork's tracked source and installable package. LiteSpeed remains a separate experiment.

## Verification on the ObviousIdea mirror

- Installed the complete ZIP using the WordPress upgrader. All 355 installed file hashes matched the manifest. WordPress and the loaded plugin both reported version 2026.11.1 for Transposh.
- The actual adapter passed ten checks on WordPress 7.1.4, PHP 8.3.36 and SEOPress 10.4, covering French and German URLs, duplicate prevention, repeat filtering, preserved image entries, foreign URLs, malformed XML, DOCTYPE, pagination limits and opting out.
- The public post and page sitemaps retained the exact same sets of 495 and 684 page URLs respectively as the previously installed adapter, with no duplicates within or across the two sitemaps. The English, French and German Light Image Resizer pages returned HTTP 200 with the expected language and noindex.
- This is a sitemap compatibility release. No production deployment or cache performance claim is made.
