<?php

declare(strict_types=1);

namespace Saloon\Barstool\Enums;

enum RecordingType: string
{
    case Request = 'request';
    case Response = 'response';
    case Fatal = 'fatal';
}
