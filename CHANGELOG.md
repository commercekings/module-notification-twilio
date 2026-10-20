# Changelog

## 0.1.1 - unreleased

- **Fixed:** a duplicate opt-in confirmation message that loses the claim no longer deletes the pending retry of the
  one that is sending it (or gave it back to retry after a refusal): the pending row ends only when the number's
  confirmation is complete. Before, that confirmation could silently never be sent.

## 0.1.0 - 2026-10-19

First release. Requires Magento 2.4.4 or later and `commercekings/module-notification`.

- **Rate limits and server errors:** HTTP 429, or 503 with `Retry-After`, is Notification's
  `TemporaryFailureException` (nothing sent, try again after the wait); any other 5xx counts as maybe sent
  (`MessageSentException`, never sent twice) - the timeout/5xx rule in [docs/provider-api.md](docs/provider-api.md).
- **Providers:** Twilio SMS (`twilio_sms`) and WhatsApp (`twilio_whatsapp`) for Notification's channels, with the
  provider selectors under *Channels and providers*; credentials per website (the auth token encrypted); SMS from a Messaging
  Service or a number, WhatsApp from its number; every message asks for the status webhook.
- **WhatsApp templates:** Content SIDs per notification type and locale, with the notification's variables in order.
- **Reply webhook** (`/cktwilio/webhook/inbound`): STOP words and WhatsApp's "Stop promotions" stop the number until
  START (`StopListInterface::REASON_REPLY`); Twilio's signature checked against the configured public URL.
- **Status webhook** (`/cktwilio/webhook/status`): undeliverable numbers are suppressed with their consents kept
  (`REASON_UNDELIVERABLE`); a refusal for a number that replied STOP (21610) is an opt-out, also when Twilio refuses at
  send time, and a send refused for an invalid or non-mobile number (21211, 21614) suppresses it. Listed in
  module-rate-limit.
- **Opt-in confirmation:** one text per number and website on its first subscription (`first_for_address`), claimed
  in Notification's delivery state; a daily cleanup. Never lost: a send refused for now (429/503 Retry-After) or
  failed before Twilio took it, a broker that is down and a lost message are retried by a 5-minute cron (8 attempts,
  then logged); one that may be out is never sent again.
- **Reply order:** Twilio's message webhook has no receive time; replies count when they arrive (Twilio does not
  retry them by default).
- **Quiet hours in the recipient's time zone** from the number (libphonenumber).
- A request that times out after it was sent is `MessageSentException`, so it is never sent twice.

No public release has been tagged or published yet.
