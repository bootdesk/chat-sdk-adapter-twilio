<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Twilio;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\MustRehydrateAttachments;
use BootDesk\ChatSDK\Core\Contracts\RequiresSyncResponse;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\UnsupportedOperationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

class TwilioAdapter implements Adapter, MustRehydrateAttachments, RequiresSyncResponse
{
    private const API_VERSION = '2010-04-01';

    private const TWILIO_MESSAGE_LIMIT = 1600;

    protected TwilioFormatConverter $formatConverter;

    protected ?TwilioWebhookVerifier $webhookVerifier = null;

    protected ?Chat $chat = null;

    public function __construct(
        protected readonly string $accountSid,
        protected readonly string $authToken,
        protected readonly ClientInterface $httpClient,
        protected readonly ?string $phoneNumber = null,
        protected readonly ?string $messagingServiceSid = null,
        protected readonly ?string $webhookUrl = null,
        protected readonly ?string $statusCallbackUrl = null,
        protected readonly ?Psr17Factory $psrFactory = null,
        protected readonly string $apiUrl = 'https://api.twilio.com',
    ) {
        $this->formatConverter = new TwilioFormatConverter;
        $this->webhookVerifier = new TwilioWebhookVerifier($authToken);
    }

    public function getName(): string
    {
        return 'twilio';
    }

    public function getBotUserId(): ?string
    {
        return $this->phoneNumber;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        if ($this->webhookVerifier instanceof TwilioWebhookVerifier) {
            $body = (string) $request->getBody();

            $this->webhookVerifier->verify($request, $body);
        }

        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $params = [];

        parse_str($body, $params);

        $messageSid = $params['MessageSid'] ?? $params['SmsMessageSid'] ?? null;
        $from = $params['From'] ?? '';
        $to = $params['To'] ?? '';
        $text = $params['Body'] ?? '';
        $numMedia = (int) ($params['NumMedia'] ?? 0);

        if ($from === '' || $to === '') {
            throw new UnsupportedOperationException('Twilio webhook is not a message — likely a status callback');
        }

        $threadId = $this->encodeThreadId([
            'sender' => $to,
            'recipient' => $from,
        ]);

        $attachments = [];

        for ($i = 0; $i < $numMedia; $i++) {
            $mediaUrl = $params["MediaUrl{$i}"] ?? '';
            $contentType = $params["MediaContentType{$i}"] ?? null;

            if ($mediaUrl !== '') {
                $attachments[] = new Attachment(
                    type: $this->attachmentType($contentType),
                    url: $mediaUrl,
                    mimeType: $contentType,
                    fetchData: [$this, 'fetchMedia'],
                    fetchMetadata: ['twilioMediaUrl' => $mediaUrl],
                );
            }
        }

        $messageId = $messageSid ?? 'twilio:'.time();

        return new Message(
            id: $messageId,
            threadId: $threadId,
            author: new Author(id: $from),
            text: $text,
            formatted: $this->formatConverter->toAst($text),
            attachments: $attachments,
            isMention: false,
            isDM: true,
            raw: $body,
        );
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $sender = rawurlencode($platformData['sender'] ?? '');
        $recipient = rawurlencode($platformData['recipient'] ?? '');

        return "twilio:{$sender}:{$recipient}";
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 3);

        if ($parts[0] !== 'twilio' || ($parts[1] ?? '') === '' || ($parts[2] ?? '') === '') {
            throw new AdapterException("Invalid Twilio thread ID: {$threadId}");
        }

        return [
            'sender' => rawurldecode($parts[1]),
            'recipient' => rawurldecode($parts[2]),
        ];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        $parts = explode(':', $threadId, 3);

        return 'twilio:'.($parts[1] ?? '');
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);

        $body = $this->renderPostableText($message);
        $mediaUrls = $this->mediaUrls($message);

        if ($body === '' && $mediaUrls === []) {
            throw new AdapterException('Message text or media URL is required');
        }

        $params = [
            'To' => $decoded['recipient'],
            ...$this->senderFields($decoded['sender']),
        ];

        if ($body !== '') {
            $params['Body'] = $body;
        }

        foreach ($mediaUrls as $mediaUrl) {
            $params['MediaUrl'][] = $mediaUrl;
        }

        if ($this->statusCallbackUrl !== null) {
            $params['StatusCallback'] = $this->statusCallbackUrl;
        }

        $raw = $this->apiCall('POST', 'Messages.json', $params);

        $resource = $raw['body'] ?? [];

        return new SentMessage(
            id: $resource['sid'] ?? '',
            threadId: $this->threadIdForResource($resource, $decoded),
            raw: $raw,
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        throw new AdapterException('Twilio does not support editing sent messages');
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        $this->apiCall('DELETE', "Messages/{$messageId}.json");
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        // Twilio does not support reactions
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        // Twilio does not support reactions
    }

    public function startTyping(string $threadId): void
    {
        // Twilio SMS does not support typing indicators
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        $options ??= new FetchOptions;
        $decoded = $this->decodeThreadId($threadId);

        $outboundRaw = $this->apiCall('GET', 'Messages.json', [
            'From' => $decoded['sender'],
            'To' => $decoded['recipient'],
            'PageSize' => $options->limit,
        ]);

        $inboundRaw = $this->apiCall('GET', 'Messages.json', [
            'From' => $decoded['recipient'],
            'To' => $decoded['sender'],
            'PageSize' => $options->limit,
        ]);

        $outboundMessages = $outboundRaw['body']['messages'] ?? [];
        $inboundMessages = $inboundRaw['body']['messages'] ?? [];

        $allRaw = array_merge($outboundMessages, $inboundMessages);

        usort($allRaw, fn (array $a, array $b): int => $this->compareMessageDates($a, $b));

        $allRaw = array_slice($allRaw, -$options->limit);

        $messages = array_map(fn (array $raw): Message => $this->parseResource($raw, $decoded), $allRaw);

        return new FetchResult(messages: $messages);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);

        return new ThreadInfo(
            id: $threadId,
            channelId: $this->channelIdFromThreadId($threadId),
            title: $decoded['recipient'],
            messageCount: 0,
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        return null;
    }

    public function getUser(string $userId): ?UserInfo
    {
        return new UserInfo(
            id: $userId,
            name: $userId,
        );
    }

    public function openDM(string $userId): ?string
    {
        $sender = $this->phoneNumber ?? $this->messagingServiceSid;

        if ($sender === null || $sender === '') {
            throw new AdapterException('phoneNumber or messagingServiceSid is required');
        }

        return $this->encodeThreadId([
            'sender' => $sender,
            'recipient' => $userId,
        ]);
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        $this->chat = $chat;
    }

    public function disconnect(): void
    {
        // No persistent connection to close
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';

        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    public function createResponse(): ?ResponseInterface
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        return $factory->createResponse(200)
            ->withHeader('content-type', 'application/xml')
            ->withBody($factory->createStream('<Response></Response>'));
    }

    public function rehydrateAttachment(Attachment $attachment): Attachment
    {
        $mediaUrl = $attachment->fetchMetadata['twilioMediaUrl'] ?? $attachment->url;

        if ($mediaUrl === null || $mediaUrl === '') {
            return $attachment;
        }

        return $attachment->withFetchOptions(fetchData: [$this, 'fetchMedia'], fetchMetadata: ['twilioMediaUrl' => $mediaUrl]);
    }

    protected function renderPostableText(PostableMessage $message): string
    {
        if ($message->isCard()) {
            $text = TwilioCards::cardToText($message->content);
        } else {
            $text = $this->formatConverter->renderPostable($message);
        }

        return mb_substr($text, 0, self::TWILIO_MESSAGE_LIMIT);
    }

    protected function mediaUrls(PostableMessage $message): array
    {
        $urls = [];

        foreach ($message->attachments as $attachment) {
            if ($attachment->url !== null && $attachment->url !== '') {
                $urls[] = $attachment->url;
            }
        }

        return $urls;
    }

    protected function senderFields(string $sender): array
    {
        if (str_starts_with($sender, 'MG')) {
            return ['MessagingServiceSid' => $sender];
        }

        return ['From' => $sender];
    }

    protected function attachmentType(?string $contentType): string
    {
        if ($contentType === null) {
            return 'file';
        }

        if (str_starts_with($contentType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($contentType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($contentType, 'audio/')) {
            return 'audio';
        }

        return 'file';
    }

    public function fetchMedia(Attachment $attachment): StreamInterface
    {
        $url = $attachment->fetchMetadata['twilioMediaUrl'] ?? $attachment->url;

        if ($url === null || $url === '') {
            throw new AdapterException('No media URL available');
        }

        $raw = $this->apiCall('GET', null, [], $url, returnStream: true);

        return $raw['stream'];
    }

    protected function parseResource(array $raw, array $fallbackThread): Message
    {
        $isMe = isset($raw['direction']) && str_starts_with($raw['direction'], 'outbound');
        $from = $raw['from'] ?? $raw['messaging_service_sid'] ?? ($isMe ? $fallbackThread['sender'] : $fallbackThread['recipient']);
        $to = $raw['to'] ?? ($isMe ? $fallbackThread['recipient'] : $fallbackThread['sender']);

        if ($from === null || $to === null || $from === '' || $to === '') {
            throw new AdapterException('Twilio message is missing routing information');
        }

        $text = $raw['body'] ?? '';

        $thread = $isMe
            ? ['sender' => $fallbackThread['sender'] ?? $to, 'recipient' => $fallbackThread['recipient'] ?? $from]
            : ['sender' => $from, 'recipient' => $to];

        $this->parseDate($raw['date_sent'] ?? $raw['date_created'] ?? null);

        return new Message(
            id: $raw['sid'] ?? '',
            threadId: $this->encodeThreadId($thread),
            author: new Author(id: $isMe ? $thread['sender'] : $from, isMe: $isMe),
            text: $text,
            formatted: $this->formatConverter->toAst($text),
            attachments: [],
            isMention: false,
            isDM: true,
            raw: json_encode($raw),
        );
    }

    protected function threadIdForResource(array $raw, array $fallback): string
    {
        return $this->parseResource($raw, $fallback)->threadId;
    }

    protected function parseDate(?string $value): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable;
        }

        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $value);

        if ($date !== false) {
            return $date;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return new \DateTimeImmutable;
        }
    }

    private function compareMessageDates(array $a, array $b): int
    {
        $aDate = $a['date_sent'] ?? $a['date_created'] ?? '';
        $bDate = $b['date_sent'] ?? $b['date_created'] ?? '';

        return strcmp($aDate, $bDate);
    }

    protected function apiCall(string $method, ?string $path, array $params = [], ?string $overrideUrl = null, bool $returnStream = false): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        $url = $overrideUrl;

        if ($url === null) {
            $accountSidEncoded = rawurlencode($this->accountSid);
            $url = rtrim($this->apiUrl, '/').'/'.self::API_VERSION."/Accounts/{$accountSidEncoded}/{$path}";
        }

        $request = $factory->createRequest($method, $url);

        $encodedCredentials = base64_encode("{$this->accountSid}:{$this->authToken}");
        $request = $request->withHeader('Authorization', "Basic {$encodedCredentials}");

        $bodyParams = [];
        $queryParams = [];

        if ($method === 'GET' || $method === 'DELETE') {
            $queryParams = $params;
        } else {
            $bodyParams = $params;
        }

        if ($queryParams !== []) {
            $uri = $request->getUri();

            $existingQuery = $uri->getQuery();
            $allParams = [];

            if ($existingQuery !== '') {
                parse_str($existingQuery, $allParams);
            }

            foreach ($queryParams as $key => $value) {
                $allParams[$key] = is_array($value) ? $value : $value;
            }

            $queryParts = [];

            foreach ($allParams as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $queryParts[] = rawurlencode($key).'='.rawurlencode((string) $v);
                    }
                } else {
                    $queryParts[] = rawurlencode($key).'='.rawurlencode((string) $value);
                }
            }

            $uri = $uri->withQuery(implode('&', $queryParts));
            $request = $request->withUri($uri);
        }

        if ($bodyParams !== []) {
            $formBody = '';

            foreach ($bodyParams as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        if ($formBody !== '') {
                            $formBody .= '&';
                        }

                        $formBody .= rawurlencode($key).'='.rawurlencode((string) $v);
                    }
                } else {
                    if ($formBody !== '') {
                        $formBody .= '&';
                    }

                    $formBody .= rawurlencode($key).'='.rawurlencode((string) $value);
                }
            }

            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded;charset=UTF-8')
                ->withBody($factory->createStream($formBody));
        }

        $response = $this->httpClient->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $responseBody = (string) $response->getBody();
            $errorMsg = "Twilio API returned HTTP {$statusCode}: {$responseBody}";

            throw new AdapterException($errorMsg);
        }

        if ($returnStream) {
            return ['stream' => $response->getBody(), 'status' => $statusCode];
        }

        $responseBody = (string) $response->getBody();

        $data = json_decode($responseBody, true);

        return [
            'raw' => $responseBody,
            'body' => $data,
            'status' => $statusCode,
        ];
    }
}
