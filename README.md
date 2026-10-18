# Twilio for Notifications<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-notification-twilio/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-notification-twilio/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.2–8.5 · Twilio Programmable Messaging

[![License](https://badgen.net/github/license/commercekings/module-notification-twilio)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-notification-twilio)](https://github.com/commercekings/module-notification-twilio/releases) [![Checks](https://github.com/commercekings/module-notification-twilio/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-notification-twilio/actions/workflows/checks.yml)

**Text and WhatsApp messages for every CommerceKings notification, through your Twilio account.**

Email is built into Notification and works out of the box; this module adds text and WhatsApp. **Works together with:**

- [module-notification](https://github.com/commercekings/module-notification) – the base every notification runs on.
- [module-abandoned-cart-sms](https://github.com/commercekings/module-abandoned-cart-sms) – text-message cart reminders, sent through Twilio.
- [module-notification-infobip](https://github.com/commercekings/module-notification-infobip) – the alternative; one provider per channel.

- **Two channels, one setup.** SMS and WhatsApp providers for Notification; every notification on those channels is
  sent through Twilio.
- **STOP means stop.** Replies like STOP, UNSUBSCRIBE or WhatsApp's "Stop promotions" stop the number until it sends
  START; Twilio's own opt-out refusals are learned too. The webhooks check Twilio's signature.
  A reply counts when it is exactly an opt-out keyword, ignoring case, spaces and punctuation ("opt out", "STOP!!").
- **Undeliverable numbers rest.** A number Twilio reports as unreachable is left out for a while, keeping its consent.
- **Opt-in confirmation.** One text to a number's first sign-up, as US carriers require – never one per cart.
- **Quiet hours in the recipient's time zone,** worked out from the number (US state laws go by local time).
- **WhatsApp templates** per notification type and language, with the notification's own variables.
- **Never charged twice.** A timeout counts as sent unless the request provably never left the shop, so a retry
  never sends a second text.

It is a provider module: consent, sign-ups, the stop list, quiet hours and sending stay Notification's.

## Installation

```sh
composer require commercekings/module-notification-twilio:^0.1
bin/magento setup:upgrade
```

Then **Stores > Configuration > CommerceKings > Notifications > Twilio**: the account SID, auth token and a
Messaging Service (or a sending number), per website, and in the Twilio Console the incoming-message webhook
`https://your-shop/cktwilio/webhook/inbound` – [setup](docs/setup.md).

## Settings

| Field (scope) | Default |
|---|---|
| Account SID, Auth token (website) | – the token is stored encrypted |
| Messaging Service SID, SMS number, WhatsApp number (website) | – a Messaging Service is recommended for SMS |
| Public URL (website) | the store's secure base URL – the address Twilio calls, which its signature covers |
| Opt-in confirmation, text (store view) | Yes, "{{store}}: you are signed up for text messages … Reply STOP to stop, HELP for help." |
| WhatsApp templates (website) | – rows of notification type, locale, Content SID and variables |
| SMS provider, WhatsApp provider (*Delivery*, website) | the only installed one |

## Before going live

- Use a Messaging Service with Twilio's Advanced Opt-Out, or make sure the number answers STOP and HELP.
- Set the incoming-message webhook (POST) on the number or Messaging Service; delivery status is requested per message.
- Notification's *Quiet hours* are on by default for SMS and WhatsApp (20:00–08:00) – check the window; this module
  keeps them in the recipient's time zone.
- Do not rate-limit the incoming-message webhook: a refused STOP is not sent again. The status webhook may be
  limited ([module-rate-limit](https://github.com/commercekings/module-rate-limit) lists it).
- **Protect against SMS pumping.** The public sign-up endpoints can be abused to trigger paid texts (e.g. opt-in
  confirmations to premium-rate numbers) – plan for it: limit them with [module-rate-limit](https://github.com/commercekings/module-rate-limit) and turn on Twilio's
  Geo Permissions (only the countries you sell to) and SMS Pumping Protection (Fraud Guard).

## Limitations

- SMS text only (no MMS); WhatsApp approved templates only (no free text outside a conversation).
- Time zones come from the number's country and area code; a number that moved keeps its old zone.
- No delivery reports in the admin: the status webhook only acts on undeliverable numbers and opt-outs.

## More

[Setup](docs/setup.md) · [How it works](docs/how-it-works.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

## Disclaimer

These modules help you ask for, record and respect your shoppers' choices – they don't make your store compliant on
their own. You are responsible for your store's privacy: what you collect, the wording you show, your privacy policy
and whether your setup meets the laws that apply to you (e.g. GDPR, ePrivacy, TCPA, carrier rules for text
messages). Get legal advice if you are unsure.

---

**Need help installing or setting it up?** **[Get a quote →](https://commercekings.net)**
