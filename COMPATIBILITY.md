# Compatibility and verification

The first-release runtime target is Magento Open Source 2.4.9 with PHP 8.5.
The Composer PHP range is a dependency constraint, not a claim that every
Magento/PHP combination has passed runtime testing.

**Minimum: Magento Open Source 2.4.4**, enforced by `magento/framework: ^103.0.4` and
`commercekings/module-notification`. It uses Guzzle (`guzzlehttp/guzzle`, shipped with Magento) and
`giggsey/libphonenumber-for-php` 8.13+ or 9 (Magento 2.4.9 ships 9) for time zones.

| Magento Open Source | PHP | Runtime verification |
| --- | --- | --- |
| 2.4.9 | 8.5 | Passed locally: Composer, DI, unit and integration tests, PHPStan level 5, coding standard, live webhook checks below |
| 2.4.4–2.4.8 | 8.2–8.4 | Unverified; use a matching Magento dependency set and services |

Live checks on 2026-10-18 in the CommerceKings sandbox (Magento 2.4.9, no Twilio account): a signed STOP to
`/cktwilio/webhook/inbound` stopped the number with the reason *reply* and answered empty TwiML, START lifted it, a
forged signature answered 403 and changed nothing, a signed status callback with error 30003 suppressed the WhatsApp
number as *undeliverable*, an invalid sender was answered 200 and ignored, and GET answered 404. Sending against
Twilio's real API is not yet verified (no test credentials).

The GitHub workflow checks package metadata, PHP syntax on 8.2–8.5, Git archive contents and the unit tests. Latest
results are available in [GitHub Actions](https://github.com/commercekings/module-notification-twilio/actions).
