# Cacheable translated shops with private commercial fragments

## Result and scope

On 26 October 2026, two isolated copies of the ObviousIdea shop on the public
www2 mirror were compared in English, French and German. The optimized copy
served a country-independent page shell from the normal page-cache path and
loaded prices and purchase controls together in one private request.

The initial content appeared sooner. Prices were deliberately not a prerequisite
for displaying the page. This is a site-specific prototype, not a new generic
Transposh shop feature or a production deployment.

| Language | Warm HTML TTFB: reference | Warm HTML TTFB: prototype | Reduction | Browser FCP: reference | Browser FCP: prototype |
| --- | ---: | ---: | ---: | ---: | ---: |
| English | 923 ms | 171 ms | 81.5% | 1,112 ms | 380 ms |
| French | 966 ms | 170 ms | 82.4% | 1,168 ms | 372 ms |
| German | 969 ms | 165 ms | 83.0% | 1,132 ms | 372 ms |

TTFB includes the measurement client's network overhead. Browser FCP is first
contentful paint, not LCP, a Google score, or a Cloudflare real-user percentile.
These results do not establish a site-wide visitor improvement.

## Measurement method

- HTTP: five requests per page/language/consent condition, interleaved by language
  and implementation, without cache-busting parameters. First responses were
  retained separately; the table uses medians of the following four. Sixty final
  requests covered both absent consent and `cookie_notice_accepted=true`.
- Every final HTTP response was 200, noindex, and in the expected language,
  without a redirect or PHP session cookie. Consent variants also retained fast
  warm responses: approximately 155–161 ms in the optimized copy.
  Cache reuse was assessed from repeated public responses and stable
  Last-Modified headers, without directly opening server cache files.
- The optimized first responses without consent were 1,302 / 803 / 777 ms for
  EN / FR / DE. This still requires cold page generation. A warm-cache result
  must not be presented as the cost of every request after a purge.
- Chromium 156, desktop viewport 1440 by 1000, no throttling, fresh browser
  contexts, three navigations per implementation/language. The last round used
  reverse order. All observations were retained; no best-score selection.
- Payment domains, the production hostname, Cloudflare's analytics beacon and
  the local Matomo collection request were blocked in those measurement
  contexts. This prevented payment navigation and synthetic analytics noise.
  Public-site analytics configuration was not changed. This controlled browser
  environment is not an exact replay of an international visitor session.
- The final request starts in the document head, in parallel with page parsing,
  and inserts its result once the DOM is available. An earlier footer-start
  implementation was measured separately and retained in the local audit.

Commercial fragments were inserted into the DOM at median times of
643 / 634 / 643 ms for EN / FR / DE. Those are DOM insertion observations,
not proof of paint of every control, completed checkout, or payment readiness.
The private request itself took approximately 482–484 ms in these browser
samples. It still uses WordPress and is not free server work.

A final exploratory LCP check used one new desktop navigation per condition,
retaining observations for two seconds after commercial content was ready.
Observed LCP was EN 1,396 → 436 ms, FR 1,288 → 428 ms, DE 1,292 → 444 ms.
The prototype introduced small layout shifts, with sums of approximately
0.021 / 0.024 / 0.022 during those short windows, versus zero in the reference.
These are single local observations, not full-session CLS, repeated LCP medians
or Cloudflare percentiles. Reserving the status/control space remains a useful
layout refinement before rollout.

## Why separating the shell matters

The site's existing price shortcode consults GeoIP for currency and VAT. Its
payment-provider selector also performs a GeoIP lookup whose country value is
unused by its active decision. The GeoIP plugin's page-cache protection prevents
one visitor's country-specific HTML from becoming a shared cached response.

The prototype keeps that protection enabled. Only the common page is shared;
prices, VAT, discounts, provider selection, affiliate fields and PayPal fields
are rendered together in a private `no-store` response. A request-local location
result is reused by the optimized renderer. No arbitrary IP lookup is exposed.

This commercial logic belongs to the site's snippets. It must not be copied into
the generic Transposh plugin. The GeoIP plugin also documents the underlying
[asynchronous personalization approach](https://github.com/yellowtree/geoip-detect/wiki/API%3A-AJAX).

Both copies retained the existing editable shop layout. They omitted the same
fixed-EUR Offer schema and non-rendered legacy HTML comments. Internal product
links and assets point to the mirror. Purchase controls were deliberately made
inert, with their original destinations and event specifications retained only
as data attributes for comparison. No payment was attempted.

## Functional checks

Thirty-one final checks passed:

- Old and optimized renderers produced matching translated text, amounts,
  original destinations, coupon event specifications and hidden fields in the
  tested EN/FR/DE cases.
- Normal visits, additional-license discounts, query-string affiliates and
  cookie affiliates were compared. The `MOREACT` coupon and PayPal `source`
  field were checked explicitly.
- France, United States and unknown-country simulations selected the expected
  existing currency rules independently of the page language. Simulated
  countries were confined to the inert prototype; this is not a live checkout
  tax validation or a test from real international IP addresses.
- Invalid country and parameter shapes were rejected; a markup-shaped value
  was rejected upstream with 403. A cross-origin request was rejected.
- Shared HTML contained no rendered commercial amounts. The commercial response
  remained private and no-store. Browser checks covered a network failure, a
  useful localized fallback without JavaScript, and a 390 by 844 viewport.

The original mirror shop content and modification timestamp, every pre-existing
snippet's source and activation state, and the Cloudflare isolation guard were
checked after testing. They were unchanged. Only two simulation pages and their
isolated helper remain active; a temporary regression fixture was deactivated.

## Generic Transposh correction discovered during the prototype

An HTML document with an empty `head` caused the parser to append statistics to
`lastChild()->outertext` when `lastChild()` was null. This could turn an otherwise
valid translated fragment into an HTTP 500. Empty body containers used for
editing spans had the same unsafe append pattern.

The parser now writes into an empty container directly while retaining the
existing append path for non-empty containers. It does not add a `head` to a
headless fragment and does not change language detection or cache eligibility.

Twenty-one assertions passed using the complete candidate parser as a separate
class in an administrator-only www2 test fixture. They covered FR/DE/Arabic,
RTL, empty containers, fragments without a head, normal documents, editing
spans, empty input, unchanged scripts/styles, reproduction of the installed
failure, and byte-for-byte normal output compatibility with the installed
parser. Plugin files were not replaced. The test fixture was then deactivated.

Run the committed regression file in a WordPress environment with Transposh:

```sh
wp eval-file test/parser-fragments.php
```

This correction is a reliability fix, not the source of the 81–83% HTML TTFB
gain above. That gain comes from the prototype's full-page cache eligibility.

## Before deployment or a release

- Review the actual payment handlers in a provider sandbox. This prototype
  intentionally cannot complete a purchase.
- Check prices, discounts, taxes, affiliations, no-JS behavior and loading/error
  states across all purchase pages, not just this shop copy.
- Recheck layout shifts, complete LCP behavior and accessibility. The marker
  timings above do not certify those properties.
- Recheck translated-content invalidation and other languages/URLs when wiring
  the reusable implementation. This experiment did not repeat a translation
  edit followed by purge on the final shop clone.
- Measure server work and request queues under representative load. Moving work
  into a private request improves initial display but does not remove all work.
- Validate the production impact using Cloudflare's exact hostname and page
  paths, with comparable devices/countries and adequate samples. A small shop
  improvement cannot explain or fix all forum-heavy aggregate weekly metrics.
- Explicit URL language priority and WP Rocket language-cache integration
  already exist in the candidate branch. Do not disable GeoIP cache protection,
  remove private headers, or change browser/GeoIP redirect policy merely to
  obtain a better number.

No production edit, plugin replacement, release, global cache-setting change,
language-policy change or email is included in this work.
