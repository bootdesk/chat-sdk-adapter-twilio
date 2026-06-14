<?php

namespace BootDesk\ChatSDK\Twilio\Tests;

use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Twilio\TwilioCards;
use PHPUnit\Framework\TestCase;

class TwilioCardsTest extends TestCase
{
    public function test_renders_card_with_title(): void
    {
        $card = Card::make()->header('Test Title');

        $text = TwilioCards::cardToText($card);

        $this->assertStringContainsString('Test Title', $text);
    }

    public function test_renders_card_with_section_text(): void
    {
        $card = Card::make()
            ->header('Card')
            ->section(fn ($s) => $s->text('Approve production deploy?'));

        $text = TwilioCards::cardToText($card);

        $this->assertStringContainsString('Approve production deploy?', $text);
    }

    public function test_renders_card_with_fields(): void
    {
        $card = Card::make()
            ->header('Deploy')
            ->section(fn ($s) => $s
                ->text('Deploy?')
                ->fields(['version' => '1.2.3'])
            );

        $text = TwilioCards::cardToText($card);

        $this->assertStringContainsString('Deploy', $text);
        $this->assertStringContainsString('Deploy?', $text);
        $this->assertStringContainsString('version: 1.2.3', $text);
    }

    public function test_strips_bold_markers(): void
    {
        $card = Card::make()
            ->header('Test')
            ->section(fn ($s) => $s->text('*bold* text'));

        $text = TwilioCards::cardToText($card);

        $this->assertStringContainsString('bold text', $text);
        $this->assertStringNotContainsString('*', $text);
    }

    public function test_renders_card_with_table(): void
    {
        $card = Card::make()
            ->header('Data')
            ->table(['name', 'age'], [
                ['Alice', '30'],
                ['Bob', '25'],
            ]);

        $text = TwilioCards::cardToText($card);

        $this->assertStringContainsString('name | age', $text);
        $this->assertStringContainsString('Alice | 30', $text);
        $this->assertStringNotContainsString('*', $text);
    }

    public function test_empty_card_returns_empty_string(): void
    {
        $card = Card::make()->header('');

        $text = TwilioCards::cardToText($card);

        $this->assertSame('', $text);
    }
}
