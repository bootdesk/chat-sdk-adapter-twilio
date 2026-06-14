<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Twilio;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use League\CommonMark\Node\Block\Document;

class TwilioFormatConverter extends BaseFormatConverter
{
    public function toAst(string $text): Document
    {
        return $this->parseMarkdown($text);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }
}
