# Twilio Adapter for Chat SDK

SMS and MMS adapter for [bootdesk/chat-sdk-core](https://github.com/bootdesk/chat-sdk). Build SMS and MMS bots with Twilio Messaging webhooks and the Messages API.

## Installation

```bash
composer require bootdesk/chat-sdk-adapter-twilio
```

## Usage

```php
use BootDesk\ChatSDK\Twilio\TwilioAdapter;
use BootDesk\ChatSDK\Core\Chat;
use Nyholm\Psr7\Factory\Psr17Factory;
use Http\Discovery\Psr18Client;

$httpClient = Psr18Client::create();
$psrFactory = new Psr17Factory;

$bot = new Chat([
    'adapters' => [
        'twilio' => new TwilioAdapter(
            accountSid: getenv('TWILIO_ACCOUNT_SID'),
            authToken: getenv('TWILIO_AUTH_TOKEN'),
            httpClient: $httpClient,
            phoneNumber: getenv('TWILIO_PHONE_NUMBER'),
            psrFactory: $psrFactory,
        ),
    ],
]);

$bot->onDirectMessage(fn ($thread, $message) => {
    $thread->post("You said: {$message->text}");
});
```

## Configuration

| Parameter | Description |
|---|---|
| `accountSid` | Twilio Account SID |
| `authToken` | Twilio Auth Token |
| `phoneNumber` | Sender phone number (e.g., `+15551234567`) |
| `messagingServiceSid` | Messaging Service SID (starts with `MG`) |
| `webhookUrl` | Public webhook URL (for signature verification) |
| `statusCallbackUrl` | URL for delivery status callbacks |
| `apiUrl` | Twilio API base URL (default: `https://api.twilio.com`) |

Use `phoneNumber` for a single Twilio number, or `messagingServiceSid` when sending through a Twilio Messaging Service.

## Webhook

Point your Twilio Messaging webhook to a route that calls the Chat:

```php
$response = $bot->handleWebhook('twilio', $request);
```

The adapter verifies the `x-twilio-signature` header using HMAC-SHA1 and your auth token.

## Media

Inbound MMS media is exposed as attachments. Each attachment carries `fetchData: [$adapter, 'fetchMedia']` — a callable that returns a PSR-7 `StreamInterface` with HTTP Basic auth. Use `$attachment->read(): ?StreamInterface` to access the body.

### Serialization & Queue Jobs

`Attachment::__serialize()` strips `fetchData` (not serializable). After deserialization in a queue job, `Chat::dispatchIncomingMessage()` auto-rehydrates attachments via the `MustRehydrateAttachments` interface — calls `$adapter->rehydrateAttachment($attachment)` to restore the authenticated callable.

For manual rehydration outside the Chat flow:

```php
$rehydrated = $adapter->rehydrateAttachment($attachment);
$stream = $rehydrated->read();
$body = (string) $stream;
```

Outbound MMS supports attachments with public `url` values.

## Thread IDs

Format: `twilio:{sender}:{recipient}` — both components are URI-encoded.

```php
$threadId = $adapter->encodeThreadId([
    'sender' => '+15551234567',   // bot's number
    'recipient' => '+15557654321', // user's number
]);
// "twilio:%2B15551234567:%2B15557654321"
```

## License

MIT
