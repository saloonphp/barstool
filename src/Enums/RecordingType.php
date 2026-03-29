<?php

declare(strict_types=1);

namespace Saloon\Barstool\Enums;

enum RecordingType: string
{
    case REQUEST = 'request';
    case RESPONSE = 'response';
    case FATAL = 'fatal';
}
