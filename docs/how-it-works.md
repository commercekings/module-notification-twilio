# How it works

The parts of `commercekings/module-notification-twilio`. Start with the [README](../README.md).

| Part | What it does | Notification extension point |
|---|---|---|
| `Model\Provider\SmsProvider`, `WhatsappProvider` | `twilio_sms`, `twilio_whatsapp`: send a delivery through `Model\MessageSender` | `ProviderInterface`, `Model\ProviderPool` "providers"; selectors with `Model\Config\Source\Provider` (virtual types, "channel") |
| `Model\MessageSender` | the message: SMS text, or a WhatsApp template (`Model\TemplateMap`: `Delivery::$type` and the store's locale) with `ContentVariables`; a status callback on every message | `Data\Delivery`, `Data\DeliveryContent` |
| `Model\Http\TwilioClient` | Twilio's REST API over Guzzle: a refusal is `TwilioException` (Twilio's code), no connection is a plain failure, no answer after sending is `MessageSentException` – never sent twice | `Exception\MessageSentException` |
| `Controller\Webhook\Inbound` | replies: STOP words (and WhatsApp's "Stop promotions") → `stop(…, REASON_REPLY)`, START words → `resume()`; empty TwiML | `StopListInterface` |
| `Controller\Webhook\Status` | delivery status: 30003, 30005, 30006, 21211, 21614 → `REASON_UNDELIVERABLE` (consents kept); 21610 → `REASON_REPLY` | `StopListInterface` |
| `Model\Webhook\Signature` | `X-Twilio-Signature` against the configured Public URL of each website of the account; 403 otherwise | – |
| `Observer\QueueOptinConfirmation`, `Model\Optin\Confirmation` | `first_for_address` → queue `twilio_optin`; one text per number and website, claimed in the delivery state (type `twilio_optin`, reference = the contact) before it is sent; not stoppable, no cooldown | `commercekings_notification_subscription_saved`, `QueueInterface`, `NotificationTypeInterface` (`Model\TypePool`), `DeliveryStateInterface`, `SenderInterface` |
| `Cron\CleanUpOptinState` | daily 03:35: drops those claims for contacts Notification no longer keeps | `ContactRepositoryInterface` |
| `Model\NumberZones` | the number's time zones (libphonenumber) for quiet hours; none for an unknown number or the whole store | `QuietHoursZoneInterface`, `Model\QuietHours` "zones" |
| di.xml `EndpointRegistry` | the status webhook in module-rate-limit (not the reply webhook) | module-rate-limit "definitions" |

**Senders and numbers.** SMS goes from the Messaging Service when set, else the number; WhatsApp from its number,
else the Messaging Service. Twilio addresses (`whatsapp:+…`) become Notification's channel and E.164 number; a number
without `+` gets one. An address Notification refuses is ignored and still answered 200, as Twilio does not resend.

**A refused send.** Twilio refusing a number that replied STOP to this sender (21610) stops it in Notification (reason
*reply*) and fails the send; other refusals only fail it, and the feature retries on its own schedule.

**Opt-in.** The confirmation goes only when Twilio is the channel's selected provider and *Opt-in confirmation* is on
for the store. WhatsApp sends the `twilio_optin` template instead of the text.

## Tests

From the Magento root, with the package installed:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification-twilio/Test/Unit
MAGENTO_ROOT=$PWD vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification-twilio/Test/Integration
```

No test calls Twilio: the unit tests use Guzzle's mock handler, the integration tests a stub client.
