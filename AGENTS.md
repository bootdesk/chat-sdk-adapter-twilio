# adapter-twilio

Twilio adapter for bootdesk/chat-sdk-core (SMS, MMS). Namespace: `BootDesk\ChatSDK\Twilio`

## files
- `TwilioAdapter` — implements `Adapter` using Twilio Messages API
- `TwilioFormatConverter` — Twilio text ↔ CommonMark AST
- `TwilioWebhookVerifier` — HMAC-SHA1 signature verification
- `TwilioCards` — card to plain SMS text conversion

## registration
`src/register.php` registers `'twilio' => TwilioAdapter::class` via `AdapterRegistry`

## constructor
```php
new TwilioAdapter(
    string $accountSid,
    string $authToken,
    ClientInterface $httpClient,
    ?string $phoneNumber = null,
    ?string $messagingServiceSid = null,
    ?string $webhookUrl = null,
    ?string $statusCallbackUrl = null,
    ?Psr17Factory $psrFactory = null,
    string $apiUrl = 'https://api.twilio.com',
);
```

## thread ID format
`twilio:{sender}:{recipient}` — sender is bot/our side, recipient is user. Both URI-encoded.

## contracts implemented
- `RequiresSyncResponse` — Twilio expects TwiML response in same HTTP request
- `MustRehydrateAttachments` — auto-rehydrates `Attachment::fetchData` after queue deserialization via `dispatchIncomingMessage()`

## webhook flow
1. `verifyWebhook` — verifies HMAC-SHA1 signature from `x-twilio-signature` header using auth token
2. `parseWebhook` — parses form-encoded body into Message with text + MMS media attachments
   - Attachments set `fetchData: [$this, 'fetchMedia']` (callable array, not closure — serialization-safe)
3. Non-text payloads (status callbacks) return empty Message with empty threadId
4. `createResponse` — returns `<Response></Response>` TwiML XML

## media & serialization
- `fetchMedia(Attachment): StreamInterface` — makes authenticated GET to Twilio media URL, returns PSR-7 stream directly (no in-memory buffering)
- `apiCall()` supports `$returnStream` param — returns `response->getBody()` directly for media fetches
- `Attachment::__serialize` strips `fetchData`; after unserialize, `Chat::dispatchIncomingMessage` auto-rehydrates via `MustRehydrateAttachments`
- `rehydrateAttachment(Attachment): Attachment` — creates new Attachment with fresh `[$this, 'fetchMedia']` callable
- `Attachment::read(): ?StreamInterface` — public API to read attachment body

## features
- Send SMS — max 1600 chars, auto-truncated
- Send MMS — media attachments via public URL
- Delete messages via Messages API
- Fetch message history by thread (inbound + outbound)
- Media rehydration — callable-based `fetchData` with Basic auth, survives queue serialization
- No editing (SMS protocol limitation — throws AdapterException)
- No reactions, no typing indicators (no-ops)
- Streaming — concatenates chunks into single SMS
- Webhook signature verification (HMAC-SHA1)
- Supports both phone number and Messaging Service SID sending

## config (laravel)
```php
'twilio' => [
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    'phone_number' => env('TWILIO_PHONE_NUMBER'),
    'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
],
```
