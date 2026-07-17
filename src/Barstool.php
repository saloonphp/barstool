<?php

declare(strict_types=1);

namespace Saloon\Barstool;

use Saloon\Http\Response;
use Illuminate\Support\Str;
use Saloon\Http\PendingRequest;
use Psr\Http\Message\UriInterface;
use Illuminate\Support\Facades\Context;
use Saloon\Barstool\Enums\RecordingType;
use Saloon\Contracts\Body\BodyRepository;
use Saloon\Barstool\Jobs\RecordBarstoolJob;
use Saloon\Repositories\Body\StreamBodyRepository;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Repositories\Body\MultipartBodyRepository;

class Barstool
{
    private const string CONTEXT_KEY = 'barstool:context';

    /**
     * Merge the given key/value pairs into the Barstool context.
     *
     * Context is stored as hidden data on Laravel's Context, so it is carried
     * into queued jobs but never leaks into the application's log context.
     *
     * @param  array<string, mixed>  $context
     */
    public static function context(array $context): void
    {
        Context::addHidden(self::CONTEXT_KEY, [...self::getContext(), ...$context]);
    }

    public static function addContext(string $key, mixed $value): void
    {
        self::context([$key => $value]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getContext(): array
    {
        /** @var array<string, mixed> $context */
        $context = Context::getHidden(self::CONTEXT_KEY, []);

        return $context;
    }

    public static function flushContext(): void
    {
        Context::forgetHidden(self::CONTEXT_KEY);
    }

    public static function shouldRecord(PendingRequest|Response|FatalRequestException $data): bool
    {
        if (config('barstool.enabled') !== true) {
            return false;
        }

        [$connector, $request] = self::resolveClasses($data);

        return self::passesOnlyList($connector, $request)
            && self::passesIgnoreList($connector, $request);
    }

    /**
     * @return array{class-string, class-string}
     */
    private static function resolveClasses(PendingRequest|Response|FatalRequestException $data): array
    {
        [$connector, $request] = match (true) {
            $data instanceof PendingRequest => [$data->getConnector(), $data->getRequest()],
            $data instanceof Response, $data instanceof FatalRequestException => [$data->getPendingRequest()->getConnector(), $data->getPendingRequest()->getRequest()],
        };

        return [get_class($connector), get_class($request)];
    }

    /**
     * When either `only` list is configured, a request must match one of them
     * to be recorded. Empty lists mean everything passes.
     *
     * @param  class-string  $connector
     * @param  class-string  $request
     */
    private static function passesOnlyList(string $connector, string $request): bool
    {
        $onlyConnectors = config('barstool.only.connectors', []);
        $onlyRequests = config('barstool.only.requests', []);

        if ($onlyConnectors === [] && $onlyRequests === []) {
            return true;
        }

        return in_array($connector, $onlyConnectors) || in_array($request, $onlyRequests);
    }

    /**
     * @param  class-string  $connector
     * @param  class-string  $request
     */
    private static function passesIgnoreList(string $connector, string $request): bool
    {
        if (in_array($connector, config('barstool.ignore.connectors', []))) {
            return false;
        }

        if (in_array($request, config('barstool.ignore.requests', []))) {
            return false;
        }

        return true;
    }

    public static function record(PendingRequest|Response|FatalRequestException $data): void
    {
        match (true) {
            $data instanceof PendingRequest => self::recordRequest($data),
            $data instanceof Response => self::recordResponse($data),
            $data instanceof FatalRequestException => self::recordFatal($data),
        };
    }

    /**
     * @return array{
     *      connector_class: class-string,
     *      request_class: class-string,
     *      method: string,
     *      url: string,
     *      request_headers: array<string, string>|null,
     *      request_body: BodyRepository|string|null,
     *      successful: false,
     *      context?: array<string, mixed>
     * }
     */
    private static function getRequestData(PendingRequest $request): array
    {
        $body = $request->body();

        $body = match (true) {
            $body instanceof StreamBodyRepository => '<Streamed Body>',
            $body instanceof MultipartBodyRepository => '<Multipart Body>',
            default => $body,
        };

        $data = [
            'connector_class' => get_class($request->getConnector()),
            'request_class' => get_class($request->getRequest()),
            'method' => $request->getMethod()->value,
            'url' => $request->getUrl(),
            'request_headers' => self::getRequestHeaders($request),
            'request_body' => $body,
            'successful' => false,
        ];

        // Only reference the context column when there is context to store, so upgraded
        // installs that have not run the add-context migration are unaffected.
        $context = self::getContext();

        if ($context !== []) {
            $data['context'] = $context;
        }

        return $data;
    }

    /**
     * @return array{
     *      url: UriInterface,
     *      response_headers: array<string, mixed>,
     *      response_body: string,
     *      response_status: int,
     *      successful: bool
     * }
     */
    private static function getResponseData(Response $response): array
    {
        $responseBody = self::getResponseBody($response);

        return [
            'url' => $response->getPsrRequest()->getUri(),
            'response_headers' => $response->headers()->all(),
            'response_body' => $responseBody,
            'response_status' => $response->status(),
            'successful' => $response->successful(),
        ];
    }

    /**
     * @return array{
     *      url: UriInterface,
     *      response_headers: null,
     *      response_body: null,
     *      response_status: null,
     *      successful: false,
     *      fatal_error: string
     * }
     */
    private static function getFatalData(FatalRequestException $exception): array
    {
        return [
            'url' => $exception->getPendingRequest()->getUri(),
            'response_headers' => null,
            'response_body' => null,
            'response_status' => null,
            'successful' => false,
            'fatal_error' => $exception->getMessage(),
        ];
    }

    private static function recordRequest(PendingRequest $data): void
    {
        $uuid = Str::uuid()->toString();

        $data->headers()->add('X-Barstool-UUID', $uuid);

        self::persist(RecordingType::REQUEST, self::getRequestData($data), $uuid);
    }

    private static function recordResponse(Response $data): void
    {
        $psrRequest = $data->getPsrRequest();

        $uuid = $psrRequest->getHeader('X-Barstool-UUID')[0] ?? null;
        if (is_null($uuid)) {
            return;
        }

        $payload = [
            'duration' => self::calculateDuration($data),
            ...self::getResponseData($data),
        ];

        self::persist(RecordingType::RESPONSE, $payload, $uuid);
    }

    public static function calculateDuration(Response|PendingRequest $data): int
    {
        $config = $data->getConnector()->config();

        $requestTime = (int) $config->get('barstool-request-time');
        $responseTime = (int) $config->get('barstool-response-time', microtime(true) * 1000);

        return $responseTime - $requestTime;
    }

    private static function recordFatal(FatalRequestException $data): void
    {
        $pendingRequest = $data->getPendingRequest();
        $uuid = $pendingRequest->headers()->get('X-Barstool-UUID');

        $payload = [
            'duration' => self::calculateDuration($pendingRequest),
            ...self::getFatalData($data),
        ];

        self::persist(RecordingType::FATAL, $payload, $uuid);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function persist(RecordingType $type, array $payload, string $uuid): void
    {
        if (self::shouldQueue()) {
            RecordBarstoolJob::dispatch($type, $payload, $uuid)
                ->onConnection(config('barstool.queue.connection'))
                ->onQueue(config('barstool.queue.queue'));

            return;
        }

        Models\Barstool::query()->updateOrCreate(
            ['uuid' => $uuid],
            $payload,
        );
    }

    private static function shouldQueue(): bool
    {
        return config('barstool.queue.enabled', false) === true;
    }

    /**
     * Get the supported content types for response bodies.
     *
     * @return string[]
     */
    private static function supportedContentTypes(): array
    {
        return [
            'application/json',
            'application/xml',
            'application/soap+xml',
            'text/xml',
            'text/html',
            'text/plain',
        ];
    }

    /**
     * @return array<string, string>|null
     */
    public static function getRequestHeaders(PendingRequest $request): ?array
    {
        $excludedHeaders = config('barstool.excluded_request_headers', []);
        $headers = collect($request->headers()->all());

        // Check if all headers are excluded
        if (in_array('*', $excludedHeaders)) {
            return $headers->reject(fn ($value, $key) => $key !== 'X-Barstool-UUID')->toArray();
        }

        // Check if the connector class is excluded
        if (in_array(get_class($request->getConnector()), $excludedHeaders)) {
            return $headers->reject(fn ($value, $key) => $key !== 'X-Barstool-UUID')->toArray();
        }

        // Check if the request class is excluded
        if (in_array(get_class($request->getRequest()), $excludedHeaders)) {
            return $headers->reject(fn ($value, $key) => $key !== 'X-Barstool-UUID')->toArray();
        }

        return $headers->map(function ($value, $key) use ($excludedHeaders) {
            if (in_array($key, $excludedHeaders)) {
                $value = 'REDACTED';
            }

            return $value;
        })->toArray();
    }

    public static function getResponseBody(Response $response): string
    {
        $excludedBodies = config('barstool.excluded_response_body', []);

        // Check if all bodies are excluded
        if (in_array('*', $excludedBodies)) {
            return 'REDACTED';
        }

        // Check if the connector class is excluded
        if (in_array(get_class($response->getConnector()), $excludedBodies)) {
            return 'REDACTED';
        }

        // Check if the request class is excluded
        if (in_array(get_class($response->getRequest()), $excludedBodies)) {
            return 'REDACTED';
        }

        // Non-seekable bodies (e.g. Guzzle's `stream => true`) can only be read once, so reading
        // one here would leave it at EOF and hand the application an empty body. Record a
        // placeholder instead, matching how streamed request bodies are handled.
        if (! $response->getPsrResponse()->getBody()->isSeekable()) {
            return '<Streamed Body>';
        }

        $contentTypeHeaderKey = $response->headers()->get('Content-Type') ? 'Content-Type' : 'content-type';

        if (! Str::startsWith(mb_strtolower((string) $response->headers()->get($contentTypeHeaderKey)), self::supportedContentTypes())) {
            return '<Unsupported Barstool Response Content>';
        }

        $body = $response->body();

        return self::checkContentSize($body) ? $body : '<Unsupported Barstool Response Content>';
    }

    /**
     * Check if the content is within limits
     */
    private static function checkContentSize(mixed $body): bool
    {
        try {
            $body = (string) $body;

            return intdiv(mb_strlen($body), 1000) <= config('barstool.max_response_size', 100);
        } catch (\Throwable) {
            return false;
        }
    }
}
