<?php

declare(strict_types=1);

namespace Saloon\Barstool\Jobs;

use Saloon\Barstool\Models\Barstool;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class RecordBarstoolJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [5, 30];

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly string $uuid,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->uuid}-{$this->type}";
    }

    public function handle(): void
    {
        Barstool::query()->updateOrCreate(
            ['uuid' => $this->uuid],
            $this->data,
        );
    }
}
