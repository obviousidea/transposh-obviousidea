# Engine health: counters and e-mail alerts

Added in 2026.11.7. Visitors' browsers (and robots that run JavaScript) call the translation proxy for every phrase
still untranslated on a page, so an engine can fail or burn its quota without anyone noticing. This module counts the calls,
tests the engine every day and sends an e-mail when it stops working.

## What it does
- **Counters** per day, engine and language, kept 14 days: calls, phrases sent and translated, characters, origin
  (`visitor`, `admin`, `suggest`, `rest`, `health`). No text, no key, no visitor data is stored.
- **Daily test**: one real translation of one word (5 characters) through the Microsoft Translator code path, with WP-Cron.
  A failed test alerts at once. Needs a system cron or site traffic to fire.
- **Alert e-mail** after 3 failed calls in a row, at most one per engine and error type every 24 hours, then one "works again".
  Sent to the addresses typed in the *Alert e-mail addresses* field of the panel (several, comma separated, up to 5), otherwise
  to the Transposh *Mail to* setting, otherwise to the site administrator. `TRANSPOSH_ENGINE_ALERT_TO` in `wp-config.php`
  overrides all of them. The panel shows which source is in use. Errors that only mean a refused text or
  language (`request`, `unknown`) are counted but never alert on their own. Only Microsoft Translator alerts by default.
- **Where to see it**: *Engine health* at the top of Transposh > Translation Engines (status, last success, last error, today
  and 7 days, configuration warnings, "Test now" and "Send a test e-mail"), and a red notice on the Transposh pages,
  the Dashboard and the Plugins page while an engine fails.
- **Error types**: `auth` (401, e.g. 401001: bad key or region, or an Azure free resource that used its quota),
  `quota` (403), `throttle` (429), `server` (5xx), `network`, `request` (400), `config` (no key), `unknown`.

## Administrators only
- `GET transposh/v1/engine-status`: summary (today, yesterday, 7 days, per day), health, configuration warnings, next test.
- `POST transposh/v1/engine-status` with `action=test` (real test), `action=mail` (test e-mail) or `action=save` with `to` (alert addresses; empty goes back to the defaults).

## Switch off or tune
- `define('TRANSPOSH_ENGINE_MONITOR', false);` in `wp-config.php`, or the filter `transposh_engine_monitor_enabled`.
- Filter `transposh_engine_alert_engines` (default `['b']`) chooses which engines may send alerts.

## Limits
- Counters see this site only. A quota used up by another site sharing the same key shows up through the daily test, not through the counters.
- The read-modify-write of the counters is not atomic: two simultaneous calls can lose one count. They are indicators, not billing data.
- Delivery of the e-mail depends on the mail setup of the site: use "Send a test e-mail" once.

## Validation (www2 mirror)
`test/engine-monitor.php` (isolated, 67 checks) covers error classification, counters and retention, alert, cooldown, class change,
recovery, e-mail content, escaping, kill switch and database failure. On www2, a temporary mock of the Microsoft endpoint reproduced
the real error (401001), then a 503: one alert, no second mail during the cooldown, one alert for the new error type, one recovery
e-mail, the proxy answer unchanged, the public pages identical. E-mails were captured, not sent.
