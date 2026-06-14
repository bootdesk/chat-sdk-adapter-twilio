<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Twilio;

use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use Psr\Http\Message\ServerRequestInterface;

class TwilioWebhookVerifier
{
    public function __construct(
        private readonly string $authToken,
    ) {}

    public function verify(ServerRequestInterface $request, string $body): void
    {
        $signature = $request->getHeaderLine('x-twilio-signature');

        if ($signature === '') {
            throw new AdapterException('Missing x-twilio-signature header');
        }

        $url = $this->resolveUrl($request);
        $params = $this->paramsForRequest($request, $body);
        $signedParams = strtoupper($request->getMethod()) === 'GET' ? null : $params;
        $expected = $this->sign($url, $signedParams);

        if (! hash_equals($expected, $signature)) {
            throw new AdapterException('Invalid Twilio webhook signature');
        }
    }

    public function sign(string $url, ?array $params = null): string
    {
        $base = $this->signatureBase($url, $params);

        return base64_encode(hash_hmac('sha1', $base, $this->authToken, true));
    }

    public static function signatureBase(string $url, ?array $params = null): string
    {
        if ($params === null || $params === []) {
            return $url;
        }

        $base = $url;

        ksort($params);

        foreach ($params as $name => $value) {
            if (is_array($value)) {
                sort($value);

                foreach ($value as $v) {
                    $base .= $name.$v;
                }
            } else {
                $base .= $name.$value;
            }
        }

        return $base;
    }

    private function resolveUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();

        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();
        $path = $uri->getPath();

        $url = "{$scheme}://{$host}";

        if (! in_array($port, [null, 443, 80], true)) {
            $url .= ":{$port}";
        }

        return $url.$path;
    }

    private function paramsForRequest(ServerRequestInterface $request, string $body): ?array
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            $query = $request->getUri()->getQuery();
            $params = [];

            if ($query !== '') {
                parse_str($query, $params);
            }

            return $params !== [] ? $params : null;
        }

        $contentType = $request->getHeaderLine('content-type');

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $params = [];

            parse_str($body, $params);

            return $params !== [] ? $params : null;
        }

        return null;
    }
}
