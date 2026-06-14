<?php

namespace BootDesk\ChatSDK\Twilio\Tests;

use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Twilio\TwilioWebhookVerifier;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

class TwilioWebhookVerifierTest extends TestCase
{
    private Psr17Factory $factory;

    private TwilioWebhookVerifier $verifier;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;
        $this->verifier = new TwilioWebhookVerifier('test_auth_token');
    }

    public function test_signature_base_with_sorted_params(): void
    {
        $url = 'https://example.com/twilio';
        $params = [
            'To' => '+15550000002',
            'From' => '+15550000001',
            'Body' => 'hello',
        ];

        $base = TwilioWebhookVerifier::signatureBase($url, $params);

        $this->assertSame(
            'https://example.com/twilioBodyhelloFrom+15550000001To+15550000002',
            $base
        );
    }

    public function test_signature_base_with_no_params(): void
    {
        $url = 'https://example.com/twilio';

        $base = TwilioWebhookVerifier::signatureBase($url, null);

        $this->assertSame('https://example.com/twilio', $base);
    }

    public function test_signature_base_with_empty_params(): void
    {
        $url = 'https://example.com/twilio';

        $base = TwilioWebhookVerifier::signatureBase($url, []);

        $this->assertSame('https://example.com/twilio', $base);
    }

    public function test_signature_base_sorts_duplicate_params(): void
    {
        $url = 'https://example.com/twilio';
        $params = [
            'MediaUrl' => [
                'https://example.com/b.jpg',
                'https://example.com/a.jpg',
            ],
        ];

        $base = TwilioWebhookVerifier::signatureBase($url, $params);

        $this->assertSame(
            'https://example.com/twilioMediaUrlhttps://example.com/a.jpgMediaUrlhttps://example.com/b.jpg',
            $base
        );
    }

    public function test_signature_base_sorts_param_names(): void
    {
        $url = 'https://example.com/twilio';
        $params = [
            'Z' => 'last',
            'A' => 'first',
            'M' => 'middle',
        ];

        $base = TwilioWebhookVerifier::signatureBase($url, $params);

        $this->assertSame(
            'https://example.com/twilioAfirstMmiddleZlast',
            $base
        );
    }

    public function test_sign_and_verify_round_trip(): void
    {
        $verifier = new TwilioWebhookVerifier('12345');
        $url = 'https://mycompany.com/myapp';
        $params = [
            'CallSid' => 'CA1234567890ABCDE',
            'Caller' => '+12349013030',
            'Digits' => '1234',
            'From' => '+12349013030',
            'To' => '+18005551212',
        ];

        $signature = $verifier->sign($url, $params);

        $this->assertSame('3KI2uRuYyAdhZIJXcpU0izDUzWI=', $signature);
    }

    public function test_verify_passes_with_correct_signature(): void
    {
        $body = http_build_query([
            'Body' => 'hello',
            'From' => '+15550000001',
            'To' => '+15550000002',
        ]);

        $signature = $this->verifier->sign('https://example.com/twilio', [
            'Body' => 'hello',
            'From' => '+15550000001',
            'To' => '+15550000002',
        ]);

        $request = $this->factory->createServerRequest('POST', 'https://example.com/twilio')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('x-twilio-signature', $signature)
            ->withBody($this->factory->createStream($body));

        $this->verifier->verify($request, $body);

        $this->assertTrue(true);
    }

    public function test_verify_throws_for_invalid_signature(): void
    {
        $body = http_build_query([
            'Body' => 'hello',
            'From' => '+15550000001',
            'To' => '+15550000002',
        ]);

        $request = $this->factory->createServerRequest('POST', 'https://example.com/twilio')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('x-twilio-signature', 'invalid-signature')
            ->withBody($this->factory->createStream($body));

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Invalid Twilio webhook signature');

        $this->verifier->verify($request, $body);
    }

    public function test_verify_throws_for_missing_signature_header(): void
    {
        $body = http_build_query([
            'Body' => 'hello',
            'From' => '+15550000001',
            'To' => '+15550000002',
        ]);

        $request = $this->factory->createServerRequest('POST', 'https://example.com/twilio')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Missing x-twilio-signature header');

        $this->verifier->verify($request, $body);
    }

    public function test_signature_differs_when_url_changes(): void
    {
        $sig1 = $this->verifier->sign('https://example.com/webhook1', [
            'Body' => 'hello',
        ]);

        $sig2 = $this->verifier->sign('https://example.com/webhook2', [
            'Body' => 'hello',
        ]);

        $this->assertNotSame($sig1, $sig2);
    }

    public function test_signature_differs_when_params_change(): void
    {
        $sig1 = $this->verifier->sign('https://example.com/twilio', [
            'Body' => 'hello',
        ]);

        $sig2 = $this->verifier->sign('https://example.com/twilio', [
            'Body' => 'world',
        ]);

        $this->assertNotSame($sig1, $sig2);
    }
}
