<?php

declare(strict_types=1);

namespace Saloon\Barstool\Tests\Fixtures\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function resolveEndpoint(): string
    {
        return 'user/1';
    }
}
