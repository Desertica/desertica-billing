<?php

declare(strict_types=1);

namespace Desertica\Billing\Config;

enum Environment: string
{
    case Beta = 'BETA';
    case Production = 'PRODUCTION';
}
