<?php

namespace App\Enums;

enum IntegrationCheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Passing',
            self::Warn => 'Warning',
            self::Fail => 'Failing',
        };
    }
}
