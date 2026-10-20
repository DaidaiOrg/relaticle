<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Google\Service\Exception as GoogleServiceException;
use Throwable;

enum GoogleDisabledService
{
    case Mail;
    case Calendar;

    public static function fromException(Throwable $exception): ?self
    {
        if (! $exception instanceof GoogleServiceException) {
            return null;
        }

        foreach ($exception->getErrors() ?? [] as $error) {
            $reason = $error['reason'] ?? null;

            if ($reason === 'notACalendarUser') {
                return self::Calendar;
            }

            if ($reason === 'failedPrecondition' && stripos((string) ($error['message'] ?? ''), 'Mail service not enabled') !== false) {
                return self::Mail;
            }
        }

        return null;
    }
}
