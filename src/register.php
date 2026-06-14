<?php

declare(strict_types=1);

use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Twilio\TwilioAdapter;

AdapterRegistry::register('twilio', TwilioAdapter::class);
