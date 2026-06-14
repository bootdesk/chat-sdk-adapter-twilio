<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Twilio;

use BootDesk\ChatSDK\Core\Cards\Card;

class TwilioCards
{
    public static function cardToText(Card $card): string
    {
        $text = $card->getFallbackText();

        return str_replace('*', '', $text);
    }
}
