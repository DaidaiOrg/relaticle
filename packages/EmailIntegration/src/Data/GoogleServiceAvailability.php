<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

final readonly class GoogleServiceAvailability
{
    public function __construct(
        public bool $mail,
        public bool $calendar,
    ) {}
}
