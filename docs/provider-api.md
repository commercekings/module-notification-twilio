# Provider API reference

What this module calls on Twilio and what it expects back, read from the code and checked against Twilio's
documentation. Start with the [README](../README.md); the parts are described in [How it works](how-it-works.md).

**Verified:** not tested against a live Twilio account (Twilio trials are not available in our country). The calls
below are covered by unit tests (Guzzle's mock handler), integration tests (a stub client) and an end-to-end test
against a simulated Twilio API. Test on your own account before going live.

## Connection

| | |
|---|---|
| Base URL | `https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/` (`Model\Http\TwilioClient::BASE_URL`) |
| API version | `2010-04-01` (Programmable Messaging, Message resource) |
| Auth | HTTP Basic: Account SID as user, Auth Token as password (per website; the token is stored encrypted) |
| Body | `application/x-www-form-urlencoded` (Guzzle `form_params`); the answer is JSON |
| Timeouts | connect 5 s, total 15 s (`TwilioClient` constructor arguments `connectTimeout`, `timeout`) |
| HTTP errors | `http_errors: false`: every HTTP answer is read by the module, none throws in Guzzle |

Docs: [Message resource](https://www.twilio.com/docs/messaging/api/message-resource).

## Outbound: create a message

`POST /2010-04-01/Accounts/{AccountSid}/Messages.json` – one call per delivery (`Model\MessageSender::send()`).

| Field | Sent | Why |
|---|---|---|
| `To` | always | the recipient in E.164; `whatsapp:+…` on the WhatsApp channel |
| `MessagingServiceSid` | SMS: when a Messaging Service is set; WhatsApp: when no WhatsApp number is set | Twilio picks the sender and, with Advanced Opt-Out, handles STOP/START/HELP |
| `From` | otherwise | the SMS number, or `whatsapp:+…` for the WhatsApp number |
| `Body` | SMS | `DeliveryContent::$text`, trimmed; an empty text is not sent |
| `ContentSid` | WhatsApp | the approved template (`HX…`) from *WhatsApp templates*, else `Delivery::$templateId` |
| `ContentVariables` | WhatsApp, when the template has variables | JSON object `{"1": "…", "2": "…"}` from `DeliveryContent::$vars` in the mapped order |
| `StatusCallback` | when a public URL is known | `{public URL}/cktwilio/webhook/status`, so late failures reach the status webhook |

No sender configured for the channel: nothing is sent (`\RuntimeException`). WhatsApp outside a conversation takes
templates only, so free text is never sent there.

**Response read:** only the status code. A 2xx answer is success; the body (the Message resource: `sid`, `status`, …)
is returned to the caller but not stored. On a non-2xx answer the JSON body's `code` (Twilio's error code) is read.

### What a failure means

| Situation | Exception | Sent? | Effect on the number |
|---|---|---|---|
| 2xx | – | yes | – |
| non-2xx with error `21610` (recipient replied STOP to this sender) | `TwilioException` | no | `stop(…, REASON_REPLY)`, then the send fails |
| non-2xx with error `21211` (invalid `To`) or `21614` (not a mobile number) | `TwilioException` | no | `stop(…, REASON_UNDELIVERABLE)`, then the send fails |
| HTTP 429 (e.g. 20429), or 503 with `Retry-After` | `TemporaryFailureException` (`getRetryAfter()`: the header's seconds) | no | none – sent again after the wait |
| any other 5xx (500, 502, 503 without `Retry-After`, 504, …) | `MessageSentException` | maybe | none – never sent again |
| any other non-2xx (e.g. 20003 auth, 21606 bad `From`) | `TwilioException` (Twilio's code, HTTP status) | no | none – the feature retries on its own schedule |
| cURL proves the request never left: errno 5, 6 (proxy/host not resolved), 7 (connection refused), 35 (TLS) | `\RuntimeException` | no | none |
| cURL timeout (28) **with** `pretransfer_time` 0, `request_size` 0 and `size_upload` 0 | `\RuntimeException` | no | none |
| any other timeout, empty reply (52), or other transport error | `MessageSentException` | maybe | none – never sent again, so a shopper is never texted (or charged) twice |
| no Account SID or Auth Token | `\RuntimeException` | no | none |

cURL reports a connect timeout and a read timeout with the same errno 28, so only the transfer counters prove that
nothing left; without that proof a timeout counts as maybe sent.

**Timeout/5xx rule:** whenever the request may have reached Twilio and no clear refusal came back – a timeout without
cURL's proof that nothing left, an empty reply, or a 5xx other than 503 with `Retry-After` – the message counts as
maybe sent (`MessageSentException`): it is never sent again, so a shopper is never messaged (or charged) twice; a lost
message is the accepted cost. A clear "not now" (429, or 503 with `Retry-After`) is a `TemporaryFailureException`:
nothing was sent, and a feature sends it again after the wait (abandoned cart: `Retry-After`, else 15 minutes, without
counting a failed attempt).

Docs: [Error and warning dictionary](https://www.twilio.com/docs/api/errors),
[21610](https://www.twilio.com/docs/api/errors/21610).

## Webhooks

| Webhook | Path | Set where |
|---|---|---|
| Inbound replies ("A message comes in") | `POST {public URL}/cktwilio/webhook/inbound` | the number or Messaging Service in the Twilio Console |
| Status callbacks | `POST {public URL}/cktwilio/webhook/status` | `StatusCallback` on every message |

**Authentication.** `X-Twilio-Signature` (`Model\Webhook\Signature`): base64 of HMAC-SHA1, keyed with the Auth Token,
over the URL Twilio called followed by every POST parameter name and value, sorted by name, with no separators. The
URL is the configured *Public URL* (else the store's secure base URL) plus the path, never the request's own URL
(it differs behind a proxy). The request's `AccountSid` selects the websites using that account; each one's token
and URL are tried. Docs: [Webhooks security](https://www.twilio.com/docs/usage/webhooks/webhooks-security),
[Validating requests](https://www.twilio.com/docs/usage/security#validating-requests).

**Our answers.**

| Case | Answer |
|---|---|
| signature missing or wrong, or no `AccountSid` | `403`, `Invalid signature.`, nothing done |
| inbound, valid | `200`, empty TwiML `<?xml version="1.0" encoding="UTF-8"?><Response/>` (no reply text; Advanced Opt-Out or the shop sends confirmations) |
| status, valid | `200`, empty body |
| valid, but the address is not a number Notification accepts | `200`, logged without the number |

Twilio retries a webhook by default only once and only after a TCP or TLS failure, not after an HTTP error
([connection overrides](https://www.twilio.com/docs/usage/webhooks/webhooks-connection-overrides)), so a valid
request is always answered 200. Do not rate-limit the inbound webhook: a refused STOP is not sent again.

### Inbound replies – fields read

| Field | Use |
|---|---|
| `AccountSid` | signature check |
| `From` | the sender: `+…` (SMS) or `whatsapp:+…` (WhatsApp); a number without `+` gets one |
| `OptOutType` | sent by Twilio with Advanced Opt-Out on a Messaging Service: `STOP` → stop, `START` → resume, `HELP` → nothing. When present it decides |
| `ButtonPayload`, `ButtonText`, `Body` | exactly `stop promotions` (ignoring case and outer spaces) → stop: WhatsApp's marketing opt-out button |
| `Body` | the keyword rule below |

**Keyword rule** (`InboundProcessor::keyword()`): the whole reply, upper-cased, with every character that is not a
letter removed (spaces, hyphens, punctuation, digits, emoji), must equal a keyword. "Stop!!", " stop ", "Opt-out" and
"stop all" (and, as digits are removed too, "STOP 2") match; "stop please" does not. Twilio itself does not treat
"OPT OUT" (with a space) as an opt-out, while we do – the conservative direction: we stop texting a number Twilio
would still send to.

| Keywords | Effect |
|---|---|
| `STOP`, `STOPALL`, `UNSUBSCRIBE`, `CANCEL`, `END`, `QUIT`, `REVOKE`, `OPTOUT` | `stop(channel, number, REASON_REPLY)` – lifts only with START |
| `START`, `UNSTOP`, `YES` | `resume(channel, number)` – removed subscriptions do not come back |
| anything else | nothing; logged as "unrecognized reply" (no number, no text) |

These are Twilio's default opt-out and opt-in keywords; `REVOKE` and `OPTOUT` were added by Twilio in May 2025
([changelog](https://www.twilio.com/en-us/changelog/opt-out-additional-keywords-added-per-fcc-ruling),
[opt-out keywords](https://support.twilio.com/hc/en-us/articles/223134027-Twilio-Support-for-Opt-Out-Keywords-SMS-STOP-Filtering),
[OptOutType](https://support.twilio.com/hc/en-us/articles/31560110671259-How-to-Track-Opt-Out-Opt-In-and-Help-Messages-Using-the-OptOutType-Parameter)).

Docs: [Messaging webhook request](https://www.twilio.com/docs/messaging/guides/webhook-request).

### Status callbacks – fields read

| Field | Use |
|---|---|
| `AccountSid` | signature check |
| `MessageStatus` | only `failed` and `undelivered` are acted on; every other status is ignored |
| `ErrorCode` | which stop reason, below |
| `To` | the number, parsed like `From` above |

| `ErrorCode` | Meaning (Twilio) | Effect |
|---|---|---|
| `21610` | recipient unsubscribed from this sender | `stop(…, REASON_REPLY)` |
| `30003` | unreachable destination handset | `stop(…, REASON_UNDELIVERABLE)` – consents kept, lifts after the configured days |
| `30005` | unknown destination handset | same |
| `30006` | landline or unreachable carrier | same |
| `21211` | invalid `To` number | same |
| `21614` | `To` is not a valid mobile number | same |
| any other | account, sender, carrier filtering, temporary | nothing |

Docs: [Track outbound message status](https://www.twilio.com/docs/messaging/guides/track-outbound-message-status).

## WhatsApp templates

Configured under *Twilio > WhatsApp templates* (`Model\TemplateMap`): rows of notification type, locale, Content SID
and variable names. The row for the store's locale wins, then its language (`de` for `de_CH`), then an empty locale;
without a row, `Delivery::$templateId` is used as the Content SID with no variables. Variables are
`DeliveryContent::$vars` names in template order and become `ContentVariables` keys `"1"`, `"2"`, …; a missing or
non-scalar value is sent as an empty string. The opt-in confirmation uses type `twilio_optin` (variable `store`).
Docs: [Content API](https://www.twilio.com/docs/content), [Send templates](https://www.twilio.com/docs/content/send-templates-created-with-the-content-template-builder).

## Not verified

From Twilio's documentation and our tests only, not checked against a live account:

- Every call and webhook on this page (no live Twilio test yet).
- WhatsApp with no WhatsApp number set sends `MessagingServiceSid` without `From`. The Message resource allows that;
  Twilio's Content template guide lists `From` as always required (`whatsapp:…` or the `MG…` SID).
- Whether 21211 and 21614 also come in status callbacks; Twilio's error pages do not say. Both paths are handled.
