<?php

declare(strict_types=1);

namespace Saloon\Barstool;

use Saloon\Http\Response;
use Illuminate\Support\Str;
use Saloon\Http\PendingRequest;
use Psr\Http\Message\UriInterface;
use Illuminate\Support\Facades\Context;
use Saloon\Barstool\Enums\RecordingType;
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
     *      request_body: string|null,
     *      successful: false,
     *      context?: array<string, mixed>
     * }
     */
    private static function getRequestData(PendingRequest $request): array
    {
        $data = [
            'connector_class' => get_class($request->getConnector()),
            'request_class' => get_class($request->getRequest()),
            'method' => $request->getMethod()->value,
            'url' => $request->getUrl(),
            'request_headers' => self::getRequestHeaders($request),
            'request_body' => self::getRequestBody($request),
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
            'response_headers' => self::getResponseHeaders($response),
            'response_body' => $responseBody,
            'response_status' => $response->status(),
            'successful' => $response->successful(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getResponseHeaders(Response $response): array
    {
        $excludedHeaders = config('barstool.excluded_response_headers', []);
        $headers = collect($response->headers()->all());

        // '*' or a listed connector/request class redacts every header value
        if (in_array('*', $excludedHeaders)
            || in_array(get_class($response->getConnector()), $excludedHeaders)
            || in_array(get_class($response->getRequest()), $excludedHeaders)) {
            return $headers->map(fn () => 'REDACTED')->toArray();
        }

        // Header names are matched case-insensitively, consistent with the
        // request-side exclusions.
        $excludedHeaders = array_map(mb_strtolower(...), $excludedHeaders);

        return $headers->map(function ($value, $key) use ($excludedHeaders) {
            if (in_array(mb_strtolower($key), $excludedHeaders)) {
                $value = 'REDACTED';
            }

            return $value;
        })->toArray();
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

        // When successful responses are not kept, still record the outcome
        // (status, successful, duration) so the row does not read as a failure -
        // only the body and headers are omitted.
        $keepResponse = $data->failed() || config('barstool.keep_successful_responses') !== false;

        $payload = [
            'duration' => self::calculateDuration($data),
            ...($keepResponse ? self::getResponseData($data) : [
                'url' => $data->getPsrRequest()->getUri(),
                'response_headers' => null,
                'response_body' => null,
                'response_status' => $data->status(),
                'successful' => $data->successful(),
            ]),
        ];

        self::persist(RecordingType::RESPONSE, $payload, $uuid);
    }

    public static function calculateDuration(Response|PendingRequest $data): int
    {
        // Timing lives on the PendingRequest, not the connector - connector config is
        // shared between concurrent requests and the timestamps would overwrite each other.
        $config = $data instanceof Response
            ? $data->getPendingRequest()->config()
            : $data->config();

        // Subtract the raw float timestamps before rounding - truncating each
        // timestamp first introduces up to +-1ms of error on the duration.
        $requestTime = (float) $config->get('barstool-request-time');
        $responseTime = (float) $config->get('barstool-response-time', microtime(true) * 1000);

        return (int) round($responseTime - $requestTime);
    }

    private static function recordFatal(FatalRequestException $data): void
    {
        $pendingRequest = $data->getPendingRequest();

        $uuid = $pendingRequest->headers()->get('X-Barstool-UUID');
        if (! is_string($uuid) || $uuid === '') {
            return;
        }

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

    public static function getRequestBody(PendingRequest $request): ?string
    {
        $body = $request->body();

        if ($body === null) {
            return null;
        }

        if ($body instanceof StreamBodyRepository) {
            return '<Streamed Body>';
        }

        if ($body instanceof MultipartBodyRepository) {
            return '<Multipart Body>';
        }

        $excludedBodies = config('barstool.excluded_request_body', []);

        if (in_array('*', $excludedBodies)
            || in_array(get_class($request->getConnector()), $excludedBodies)
            || in_array(get_class($request->getRequest()), $excludedBodies)) {
            return 'REDACTED';
        }

        // Saloon's standard repositories are all Stringable; fall back to
        // encoding the raw contents for custom repositories that are not.
        $body = $body instanceof \Stringable
            ? (string) $body
            : (string) json_encode($body->all());

        return self::checkContentSize($body, (int) config('barstool.max_request_size', 100))
            ? $body
            : '<Unsupported Barstool Request Content>';
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

        // Header names are matched case-insensitively so `authorization` cannot
        // slip past an `Authorization` exclusion.
        $excludedHeaders = array_map(mb_strtolower(...), $excludedHeaders);

        return $headers->map(function ($value, $key) use ($excludedHeaders) {
            if (in_array(mb_strtolower($key), $excludedHeaders)) {
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

        $contentType = collect($response->headers()->all())
            ->first(fn ($value, $key) => mb_strtolower($key) === 'content-type');

        if (is_array($contentType)) {
            $contentType = $contentType[0] ?? '';
        }

        if (! Str::startsWith(mb_strtolower((string) $contentType), self::supportedContentTypes())) {
            return '<Unsupported Barstool Response Content>';
        }

        $body = $response->body();

        return self::checkContentSize($body, (int) config('barstool.max_response_size', 100))
            ? $body
            : '<Unsupported Barstool Response Content>';
    }

    /**
     * Check if the content is within limits
     */
    private static function checkContentSize(mixed $body, int $maxKilobytes): bool
    {
        try {
            $body = (string) $body;

            // strlen, not mb_strlen: the limit is about storage size, so count bytes
            return intdiv(strlen($body), 1000) <= $maxKilobytes;
        } catch (\Throwable) {
            return false;
        }
    }
}
