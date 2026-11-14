<?php

declare(strict_types=1);

namespace Saloon\Barstool\Jobs;

use DateTimeInterface;
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
     * @param  array<model-property<Barstool>, mixed>  $data
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

    /**
     * Keep a response or fatal recording alive until its request row exists.
     *
     * Releasing this job still counts as an attempt. With only three tries, the
     * worker fails it before handle() on the next pickup and the recording is lost.
     * The deadline is fixed when the job is queued, and it takes precedence over tries.
     */
    public function retryUntil(): ?DateTimeInterface
    {
        if ($this->type === RecordingType::REQUEST) {
            return null;
        }

        return now()->addMinutes(10);
    }

    public function handle(): void
    {
        if ($this->type === RecordingType::REQUEST) {
            // updateOrCreate keeps retries idempotent - a job that fails after its
            // insert committed must not create a duplicate row on the next attempt.
            Barstool::query()
                ->updateOrCreate(
                    ['uuid' => $this->uuid],
                    $this->data,
                );

            return;
        }

        $barstool = Barstool::query()
            ->where('uuid', $this->uuid)
            ->first();

        if ($barstool === null) {
            $this->release($this->releaseDelay());

            return;
        }

        $barstool->update($this->data);
    }

    /**
     * Use the configured backoff, then keep using its last delay.
     */
    private function releaseDelay(): int
    {
        $index = min($this->attempts() - 1, count($this->backoff) - 1);

        return $this->backoff[max(0, $index)];
    }
}
