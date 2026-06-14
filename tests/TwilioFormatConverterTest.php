<?php

namespace BootDesk\ChatSDK\Twilio\Tests;

use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Twilio\TwilioFormatConverter;
use League\CommonMark\Node\Block\Document;
use PHPUnit\Framework\TestCase;

class TwilioFormatConverterTest extends TestCase
{
    private TwilioFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new TwilioFormatConverter;
    }

    public function test_keeps_raw_strings_plain(): void
    {
        $result = $this->converter->renderPostable(PostableMessage::text('hello'));

        $this->assertSame('hello', $result);
    }

    public function test_converts_markdown_to_html(): void
    {
        $result = $this->converter->fromMarkdown('**hello**');

        $this->assertStringContainsString('<strong>hello</strong>', $result);
    }

    public function test_passes_table_markdown_through(): void
    {
        $text = "| name | age |\n| --- | --- |\n| Ada | 36 |";
        $ast = $this->converter->toAst($text);

        $result = $this->converter->fromAst($ast);

        $this->assertStringContainsString('Ada', $result);
        $this->assertStringContainsString('name', $result);
    }

    public function test_to_ast_returns_document(): void
    {
        $ast = $this->converter->toAst('hello');

        $this->assertInstanceOf(Document::class, $ast);
    }

    public function test_from_ast_round_trips_plain_text(): void
    {
        $input = 'Hello, world!';
        $ast = $this->converter->toAst($input);
        $result = $this->converter->fromAst($ast);

        $this->assertStringContainsString('Hello, world!', $result);
    }

    public function test_render_postable_with_card_returns_fallback_text(): void
    {
        $card = Card::make()->header('Fallback');
        $message = PostableMessage::card($card);

        $result = $this->converter->renderPostable($message);

        $this->assertStringContainsString('Fallback', $result);
    }
}
