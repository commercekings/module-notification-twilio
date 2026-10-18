# Setup

Connecting `commercekings/module-notification-twilio` to Twilio. Start with the [README](../README.md).

## In Twilio

1. **Account SID and Auth token** – Twilio Console, *Account info*.
2. **SMS sender** – a Messaging Service (recommended: Twilio picks the number, handles STOP/START/HELP with
   *Advanced Opt-Out*) or a single number. US numbers need A2P 10DLC registration.
3. **WhatsApp sender** – an approved WhatsApp sender, and approved templates (*Content Template Builder*, HX… SIDs)
   for every marketing message: WhatsApp allows free text only inside a conversation the customer started.
4. **Incoming messages** – on the number or the Messaging Service, *A message comes in*: Webhook, HTTP POST,
   `https://your-shop/cktwilio/webhook/inbound`. Delivery status needs no setting: every message asks for
   `…/cktwilio/webhook/status`.

## In Magento

**Stores > Configuration > CommerceKings > Notifications > Twilio**, per website:

| Field | |
|---|---|
| Account SID, Auth token | from the Console |
| Messaging Service SID | MG…; used for SMS, and for WhatsApp when no WhatsApp number is set |
| SMS number, WhatsApp number | E.164 (`+14155550100`) |
| Public URL | the address Twilio calls, exactly (`https://your-shop`), when it differs from the store's secure base URL (a proxy, another host name) – the signature covers it |
| Opt-in confirmation, text | per store view; `{{store}}` is the store's name |
| WhatsApp templates | one row per notification type and locale |

Install one provider module per channel: with another SMS or WhatsApp provider module installed too, selecting Twilio
under *Delivery* alone doesn't separate their recipient time zones.

### WhatsApp templates

| Notification type | Locale | Content SID | Variables, in order |
|---|---|---|---|
| `your_whatsapp_type` | `de` | `HX…` | the variables your notification type gives, e.g. `name, link` |
| `twilio_optin` | | `HX…` | `store` |

The locale matches exactly (`de_CH`), then by language (`de`), then an empty locale. The variables fill the template's
`{{1}}`, `{{2}}`, … from the notification's data (plain text only; `twilio_optin` gives `store`). No WhatsApp cart
reminder ships: the abandoned cart texts are SMS. A message without a template is not sent: the feature counts a
failed attempt.

## Check it

- Send STOP from a phone to the number: Notification's stop list has it with the reason *reply*; START lifts it.
- A wrong Auth token or Public URL answers Twilio 403 (*Invalid signature*) – visible in the Twilio Console's
  debugger.
