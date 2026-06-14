<?php

namespace BootDesk\ChatSDK\Twilio\Tests;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\MustRehydrateAttachments;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Twilio\TwilioAdapter;
use BootDesk\ChatSDK\Twilio\TwilioFormatConverter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class TwilioAdapterTest extends TestCase
{
    private TwilioAdapter $adapter;

    private Psr17Factory $factory;

    /** @var RequestInterface[] */
    private array $capturedRequests = [];

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;
        $this->capturedRequests = [];

        $captured = &$this->capturedRequests;
        $factory = $this->factory;

        $mockClient = new class($captured, $factory) implements ClientInterface
        {
            public function __construct(
                private array &$captured,
                private Psr17Factory $factory,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                $uri = (string) $request->getUri();

                if (str_contains($uri, '/Messages/SM') && $request->getMethod() === 'DELETE') {
                    return $this->factory->createResponse(204);
                }

                if (str_contains($uri, 'Messages.json')) {
                    $body = (string) $request->getBody();
                    $query = $request->getUri()->getQuery();

                    if ($request->getMethod() === 'GET') {
                        if (str_contains($query, 'From=%2B15550000001')) {
                            return $this->factory->createResponse(200)->withBody(
                                $this->factory->createStream(json_encode([
                                    'messages' => [
                                        [
                                            'sid' => 'SM200',
                                            'body' => 'Outbound reply',
                                            'direction' => 'outbound-api',
                                            'from' => '+15550000001',
                                            'to' => '+15550000002',
                                            'date_sent' => 'Tue, 01 Apr 2025 12:30:00 +0000',
                                        ],
                                    ],
                                ]))
                            );
                        }

                        return $this->factory->createResponse(200)->withBody(
                            $this->factory->createStream(json_encode([
                                'messages' => [
                                    [
                                        'sid' => 'SM999',
                                        'body' => 'Earlier',
                                        'direction' => 'inbound',
                                        'from' => '+15550000002',
                                        'to' => '+15550000001',
                                        'date_sent' => 'Tue, 01 Apr 2025 11:00:00 +0000',
                                    ],
                                    [
                                        'sid' => 'SM1000',
                                        'body' => 'Hello',
                                        'direction' => 'inbound',
                                        'from' => '+15550000002',
                                        'to' => '+15550000001',
                                        'date_sent' => 'Tue, 01 Apr 2025 12:00:00 +0000',
                                    ],
                                ],
                            ]))
                        );
                    }

                    $query = $request->getUri()->getQuery();

                    parse_str($body, $parsedBody);

                    $from = $parsedBody['From'] ?? null;
                    $messagingSid = $parsedBody['MessagingServiceSid'] ?? null;

                    return $this->factory->createResponse(200)->withBody(
                        $this->factory->createStream(json_encode([
                            'sid' => 'SM123',
                            'body' => $parsedBody['Body'] ?? '',
                            'direction' => 'outbound-api',
                            'from' => $from ?? $messagingSid ?? '+15550000001',
                            'messaging_service_sid' => $messagingSid,
                            'to' => $parsedBody['To'] ?? '+15550000002',
                            'date_sent' => '2025-04-01T12:00:00+00:00',
                        ]))
                    );
                }

                if (str_contains($uri, 'api.twilio.com')) {
                    return $this->factory->createResponse(200)->withBody(
                        $this->factory->createStream('photo-data')
                    );
                }

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode(['ok' => true]))
                );
            }
        };

        $this->adapter = new TwilioAdapter(
            accountSid: 'AC123',
            authToken: 'test_auth_token',
            httpClient: $mockClient,
            phoneNumber: '+15550000001',
            psrFactory: $this->factory,
        );
    }

    public function test_get_name(): void
    {
        $this->assertSame('twilio', $this->adapter->getName());
    }

    public function test_get_bot_user_id(): void
    {
        $this->assertSame('+15550000001', $this->adapter->getBotUserId());
    }

    public function test_encodes_and_decodes_thread_ids(): void
    {
        $thread = [
            'sender' => 'whatsapp:+15550000001',
            'recipient' => 'whatsapp:+15550000002',
        ];

        $threadId = $this->adapter->encodeThreadId($thread);

        $this->assertSame(
            'twilio:whatsapp%3A%2B15550000001:whatsapp%3A%2B15550000002',
            $threadId
        );
        $this->assertSame($thread, $this->adapter->decodeThreadId($threadId));
        $this->assertSame(
            'twilio:whatsapp%3A%2B15550000001',
            $this->adapter->channelIdFromThreadId($threadId)
        );
    }

    public function test_decode_invalid_thread_id_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Invalid Twilio thread ID');

        $this->adapter->decodeThreadId('invalid');
    }

    public function test_opens_dm_with_phone_number(): void
    {
        $threadId = $this->adapter->openDM('+15550000002');

        $this->assertSame('twilio:%2B15550000001:%2B15550000002', $threadId);
    }

    public function test_opens_dm_throws_without_sender(): void
    {
        $adapter = new TwilioAdapter(
            accountSid: 'AC123',
            authToken: 'token',
            httpClient: $this->createMock(ClientInterface::class),
        );

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('phoneNumber or messagingServiceSid is required');

        $adapter->openDM('+15550000002');
    }

    public function test_parses_incoming_webhook(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhooks/twilio')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream(http_build_query([
                'Body' => 'hello',
                'From' => '+15550000002',
                'To' => '+15550000001',
                'MessageSid' => 'SM123',
                'NumMedia' => '0',
            ])));

        $message = $this->adapter->parseWebhook($request);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('hello', $message->text);
        $this->assertSame('twilio:%2B15550000001:%2B15550000002', $message->threadId);

        $this->assertSame('+15550000002', $message->author->id);
        $this->assertFalse($message->author->isMe);
        $this->assertTrue($message->isDM);
    }

    public function test_parses_incoming_mms_webhook(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhooks/twilio')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream(http_build_query([
                'Body' => 'check this photo',
                'From' => '+15550000002',
                'To' => '+15550000001',
                'MessageSid' => 'SM456',
                'NumMedia' => '1',
                'MediaUrl0' => 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages/SM456/Media/ME789',
                'MediaContentType0' => 'image/jpeg',
            ])));

        $message = $this->adapter->parseWebhook($request);

        $this->assertCount(1, $message->attachments);
        $this->assertSame('image', $message->attachments[0]->type);
        $this->assertSame('image/jpeg', $message->attachments[0]->mimeType);
        $this->assertSame(
            'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages/SM456/Media/ME789',
            $message->attachments[0]->url
        );
    }

    public function test_rehydrates_media_with_auth(): void
    {
        $attachment = $this->adapter->rehydrateAttachment(
            new Attachment(
                type: 'image',
                url: 'https://api.twilio.com/media/photo',
                fetchMetadata: ['twilioMediaUrl' => 'https://api.twilio.com/media/photo'],
            )
        );

        $stream = $attachment->read();

        $this->assertInstanceOf(StreamInterface::class, $stream);
        $this->assertSame('photo-data', (string) $stream);

        $this->assertCount(1, $this->capturedRequests);
        $request = $this->capturedRequests[0];
        $this->assertSame(
            'Basic QUMxMjM6dGVzdF9hdXRoX3Rva2Vu',
            $request->getHeaderLine('Authorization')
        );
    }

    public function test_attachment_serialize_round_trip(): void
    {
        $attachment = new Attachment(
            type: 'image',
            url: 'https://api.twilio.com/media/photo',
            mimeType: 'image/jpeg',
            fetchData: [$this->adapter, 'fetchMedia'],
            fetchMetadata: ['twilioMediaUrl' => 'https://api.twilio.com/media/photo'],
        );

        $serialized = serialize($attachment);
        $restored = unserialize($serialized);

        $this->assertSame($attachment->type, $restored->type);
        $this->assertSame($attachment->url, $restored->url);
        $this->assertSame($attachment->mimeType, $restored->mimeType);
        $this->assertSame($attachment->fetchMetadata, $restored->fetchMetadata);
        $this->assertNull($restored->fetchData);
    }

    public function test_rehydrate_after_unserialize(): void
    {
        $original = new Attachment(
            type: 'image',
            url: 'https://api.twilio.com/media/photo',
            mimeType: 'image/jpeg',
            fetchData: [$this->adapter, 'fetchMedia'],
            fetchMetadata: ['twilioMediaUrl' => 'https://api.twilio.com/media/photo'],
        );

        $restored = unserialize(serialize($original));
        $this->assertNull($restored->fetchData);

        $rehydrated = $this->adapter->rehydrateAttachment($restored);

        $this->assertSame('image', $rehydrated->type);
        $this->assertSame('https://api.twilio.com/media/photo', $rehydrated->url);
        $this->assertSame('image/jpeg', $rehydrated->mimeType);
        $this->assertSame(
            ['twilioMediaUrl' => 'https://api.twilio.com/media/photo'],
            $rehydrated->fetchMetadata,
        );

        $stream = $rehydrated->read();

        $this->assertInstanceOf(StreamInterface::class, $stream);
        $this->assertSame('photo-data', (string) $stream);
        $this->assertSame(
            'Basic QUMxMjM6dGVzdF9hdXRoX3Rva2Vu',
            $this->capturedRequests[0]->getHeaderLine('Authorization')
        );
    }

    public function test_fetch_data_must_be_callable_or_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fetchData must be a callable or null');

        new Attachment(
            type: 'image',
            url: 'https://example.com/photo.jpg',
            fetchData: 'not-a-callable',
        );
    }

    public function test_fetch_data_null_is_allowed(): void
    {
        $attachment = new Attachment(
            type: 'image',
            url: 'https://example.com/photo.jpg',
            fetchData: null,
        );

        $this->assertNull($attachment->fetchData);
        $this->assertNull($attachment->read());
    }

    public function test_adapter_implements_must_rehydrate_interface(): void
    {
        $this->assertInstanceOf(
            MustRehydrateAttachments::class,
            $this->adapter,
        );
    }

    public function test_posts_sms_message(): void
    {
        $result = $this->adapter->postMessage(
            'twilio:%2B15550000001:%2B15550000002',
            PostableMessage::text('Hello, world!'),
        );

        $this->assertSame('SM123', $result->id);
        $this->assertSame('twilio:%2B15550000001:%2B15550000002', $result->threadId);

        $this->assertCount(1, $this->capturedRequests);
        $request = $this->capturedRequests[0];

        $body = (string) $request->getBody();
        parse_str($body, $parsed);

        $this->assertSame('Hello, world!', $parsed['Body']);
        $this->assertSame('+15550000001', $parsed['From']);
        $this->assertSame('+15550000002', $parsed['To']);
        $this->assertSame(
            'Basic QUMxMjM6dGVzdF9hdXRoX3Rva2Vu',
            $request->getHeaderLine('Authorization')
        );
    }

    public function test_posts_mms_message_with_media_urls(): void
    {
        $result = $this->adapter->postMessage(
            'twilio:%2B15550000001:%2B15550000002',
            new PostableMessage(
                content: 'with photo',
                attachments: [
                    new Attachment(
                        type: 'image',
                        url: 'https://example.com/photo.jpg',
                        mimeType: 'image/jpeg',
                    ),
                ],
            ),
        );

        $this->assertSame('SM123', $result->id);

        $this->assertCount(1, $this->capturedRequests);
        $request = $this->capturedRequests[0];

        $body = (string) $request->getBody();
        $this->assertStringContainsString('Body=with%20photo', $body);
        $this->assertStringContainsString('MediaUrl=https%3A%2F%2Fexample.com%2Fphoto.jpg', $body);
    }

    public function test_posts_with_messaging_service_sid(): void
    {
        $adapter = new TwilioAdapter(
            accountSid: 'AC123',
            authToken: 'token',
            httpClient: $this->getMockClient(),
            phoneNumber: null,
            messagingServiceSid: 'MG123',
        );

        $result = $adapter->postMessage(
            'twilio:MG123:%2B15550000002',
            PostableMessage::text('hello'),
        );

        $this->assertSame('SM123', $result->id);

        $request = $this->capturedRequests[0] ?? null;
        $this->assertNotNull($request);

        $body = (string) $request->getBody();
        $this->assertStringContainsString('MessagingServiceSid=MG123', $body);
        $this->assertStringNotContainsString('From=', $body);
    }

    public function test_edit_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Twilio does not support editing sent messages');

        $this->adapter->editMessage('twilio:%2B15550000001:%2B15550000002', 'SM123', PostableMessage::text('edited'));
    }

    public function test_deletes_message(): void
    {
        $this->adapter->deleteMessage('twilio:%2B15550000001:%2B15550000002', 'SM123');

        $this->assertCount(1, $this->capturedRequests);
        $request = $this->capturedRequests[0];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringContainsString('Messages/SM123.json', (string) $request->getUri());
    }

    public function test_add_reaction_is_noop(): void
    {
        $this->adapter->addReaction('twilio:%2B15550000001:%2B15550000002', 'SM123', 'thumbs_up');

        $this->assertCount(0, $this->capturedRequests);
    }

    public function test_remove_reaction_is_noop(): void
    {
        $this->adapter->removeReaction('twilio:%2B15550000001:%2B15550000002', 'SM123', 'thumbs_up');

        $this->assertCount(0, $this->capturedRequests);
    }

    public function test_fetches_messages(): void
    {
        $result = $this->adapter->fetchMessages('twilio:%2B15550000001:%2B15550000002');

        $this->assertCount(3, $result->messages);
        $this->assertSame('Earlier', $result->messages[0]->text);
        $this->assertSame('Hello', $result->messages[1]->text);
        $this->assertSame('Outbound reply', $result->messages[2]->text);

        $this->assertCount(2, $this->capturedRequests);
    }

    public function test_fetches_thread(): void
    {
        $info = $this->adapter->fetchThread('twilio:%2B15550000001:%2B15550000002');

        $this->assertSame('twilio:%2B15550000001:%2B15550000002', $info->id);
        $this->assertSame('twilio:%2B15550000001', $info->channelId);
        $this->assertSame('+15550000002', $info->title);
    }

    public function test_gets_user(): void
    {
        $user = $this->adapter->getUser('+15550000002');

        $this->assertSame('+15550000002', $user->id);
        $this->assertSame('+15550000002', $user->name);
    }

    public function test_returns_format_converter(): void
    {
        $this->assertInstanceOf(TwilioFormatConverter::class, $this->adapter->getFormatConverter());
    }

    public function test_initializes_with_chat(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);

        $this->adapter->disconnect();
        $this->assertTrue(true); // No exception means success
    }

    public function test_creates_twiml_response(): void
    {
        $response = $this->adapter->createResponse();

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/xml', $response->getHeaderLine('content-type'));
        $this->assertSame('<Response></Response>', (string) $response->getBody());
    }

    public function test_stream_concatenates_and_posts(): void
    {
        $result = $this->adapter->stream(
            'twilio:%2B15550000001:%2B15550000002',
            new \ArrayIterator(['Hello', ' ', 'World']),
        );

        $this->assertNotNull($result);
        $this->assertSame('SM123', $result->id);

        $this->assertCount(1, $this->capturedRequests);
        $request = $this->capturedRequests[0];
        $body = (string) $request->getBody();
        $this->assertStringContainsString('Body=Hello%20World', $body);
    }

    public function test_stream_empty_returns_null(): void
    {
        $result = $this->adapter->stream(
            'twilio:%2B15550000001:%2B15550000002',
            new \ArrayIterator([]),
        );

        $this->assertNull($result);
    }

    public function test_returns_empty_message_for_non_text_webhook(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhooks/twilio')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream(http_build_query([
                'MessageStatus' => 'delivered',
                'MessageSid' => 'SM123',
            ])));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('', $message->threadId);
        $this->assertSame('', $message->text);
    }

    public function test_post_message_with_no_body_and_no_media_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Message text or media URL is required');

        $this->adapter->postMessage(
            'twilio:%2B15550000001:%2B15550000002',
            new PostableMessage(content: ''),
        );
    }

    public function test_post_message_with_media_only_succeeds(): void
    {
        $result = $this->adapter->postMessage(
            'twilio:%2B15550000001:%2B15550000002',
            new PostableMessage(
                content: '',
                attachments: [
                    new Attachment(
                        type: 'image',
                        url: 'https://example.com/photo.jpg',
                        mimeType: 'image/jpeg',
                    ),
                ],
            ),
        );

        $this->assertSame('SM123', $result->id);

        $request = $this->capturedRequests[0] ?? null;
        $this->assertNotNull($request);

        $body = (string) $request->getBody();
        $this->assertStringContainsString('MediaUrl=https%3A%2F%2Fexample.com%2Fphoto.jpg', $body);
        $this->assertStringNotContainsString('Body=', $body);
    }

    public function test_api_error_throws_adapter_exception(): void
    {
        $factory = $this->factory;
        $captured = &$this->capturedRequests;

        $errorClient = new class($captured, $factory) implements ClientInterface
        {
            public function __construct(
                private array &$captured,
                private Psr17Factory $factory,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return $this->factory->createResponse(400)->withBody(
                    $this->factory->createStream(json_encode([
                        'message' => 'bad request',
                        'code' => 20001,
                    ]))
                );
            }
        };

        $adapter = new TwilioAdapter(
            accountSid: 'AC123',
            authToken: 'token',
            httpClient: $errorClient,
            phoneNumber: '+15550000001',
            psrFactory: $factory,
        );

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Twilio API returned HTTP 400');

        $adapter->postMessage(
            'twilio:%2B15550000001:%2B15550000002',
            PostableMessage::text('hello'),
        );
    }

    public function test_long_text_is_truncated(): void
    {
        $longText = str_repeat('x', 2000);

        $result = $this->adapter->postMessage(
            'twilio:%2B15550000001:%2B15550000002',
            PostableMessage::text($longText),
        );

        $this->assertSame('SM123', $result->id);

        $request = $this->capturedRequests[0] ?? null;
        $this->assertNotNull($request);

        $body = (string) $request->getBody();
        parse_str($body, $parsed);

        $this->assertSame(1600, strlen($parsed['Body']));
    }

    public function test_fetch_channel_info_returns_null(): void
    {
        $this->assertNull($this->adapter->fetchChannelInfo('twilio:%2B15550000001'));
    }

    public function test_get_bot_user_id_with_messaging_service(): void
    {
        $adapter = new TwilioAdapter(
            accountSid: 'AC123',
            authToken: 'token',
            httpClient: $this->createMock(ClientInterface::class),
            messagingServiceSid: 'MG123',
        );

        $this->assertNull($adapter->getBotUserId());
    }

    public function test_start_typing_is_noop(): void
    {
        $this->adapter->startTyping('twilio:%2B15550000001:%2B15550000002');

        $this->assertCount(0, $this->capturedRequests);
    }

    public function test_rehydrate_attachment_returns_original_when_no_media_url(): void
    {
        $attachment = new Attachment(
            type: 'image',
            url: null,
        );

        $result = $this->adapter->rehydrateAttachment($attachment);

        $this->assertSame($attachment, $result);
    }

    private function getMockClient(): ClientInterface
    {
        $captured = &$this->capturedRequests;
        $factory = $this->factory;

        return new class($captured, $factory) implements ClientInterface
        {
            public function __construct(
                private array &$captured,
                private Psr17Factory $factory,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                $body = (string) $request->getBody();

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'sid' => 'SM123',
                        'body' => 'hello',
                        'direction' => 'outbound-api',
                        'to' => '+15550000002',
                        'date_sent' => '2025-04-01T12:00:00+00:00',
                    ]))
                );
            }
        };
    }
}
