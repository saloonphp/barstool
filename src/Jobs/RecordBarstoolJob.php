<?php

declare(strict_types=1);

namespace Saloon\Barstool\Jobs;

use Saloon\Barstool\Models\Barstool;
use Saloon\Barstool\Enums\RecordingType;
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly RecordingType $type,
        public readonly array $data,
        public readonly string $uuid,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->uuid}-{$this->type->value}";
    }

    public function handle(): void
    {
        Barstool::query()->updateOrCreate(
            ['uuid' => $this->uuid],
            $this->data,
        );
    }
}
