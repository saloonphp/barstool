<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Barstool\Models\Barstool;
use Saloon\Http\Faking\MockResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Artisan;
use Saloon\Barstool\Enums\RecordingType;
use Saloon\Http\Connectors\NullConnector;
use Saloon\Barstool\Jobs\RecordBarstoolJob;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseEmpty;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Barstool\Tests\Fixtures\Requests\PutRequest;
use Saloon\Barstool\Tests\Fixtures\Requests\PostRequest;
use Saloon\Barstool\Tests\Fixtures\Requests\PatchRequest;
use Saloon\Barstool\Tests\Fixtures\Requests\DeleteRequest;
use Saloon\Barstool\Tests\Fixtures\Requests\GetFileRequest;
use Saloon\Barstool\Tests\Fixtures\Requests\SoloUserRequest;
use Saloon\Barstool\Tests\Fixtures\Connectors\RandomConnector;
use Saloon\Barstool\Tests\Fixtures\Requests\MultipartPostRequest;
use Saloon\Barstool\Tests\Fixtures\Requests\RequestWithConnector;

it('can be enabled', function () {
    config()->set('barstool.enabled', true);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'John Wayne'],
                    ['name' => 'Billy the Kid'],
                ],
            ],
            status: 200,
        ),
    ]);

    $response = (new SoloUserRequest)->send();

    expect($response->status())->toBe(200);
    expect($response->json())->toBe([
        'data' => [
            ['name' => 'John Wayne'],
            ['name' => 'Billy the Kid'],
        ],
    ]);

    assertDatabaseCount('barstools', 1);
    assertDatabaseHas('barstools', [
        'connector_class' => NullConnector::class,
        'request_class' => SoloUserRequest::class,
        'method' => 'GET',
        'url' => 'https://tests.saloon.dev/api/user',
        'response_status' => 200,
        'successful' => true,
    ]);
});

it('can be disabled', function () {
    config()->set('barstool.enabled', false);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'John Wayne'],
                    ['name' => 'Billy the Kid'],
                ],
            ],
            status: 200,
        ),
    ]);

    $response = (new SoloUserRequest)->send();
    expect($response->status())->toBe(200);

    assertDatabaseCount('barstools', 0);
});

it('can change the database connection', function () {
    expect(Barstool::make()->getConnectionName())->toBe('mysql');

    config()->set('barstool.connection', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    expect(Barstool::make()->getConnectionName())->toBe('sqlite');
});

it('can change the number of days to keep recordings for', function () {
    // Check the default value is 30
    expect(config('barstool.keep_for_days'))->toBe(30);

    $this->travel(-10)->days();
    Barstool::factory()->count(2)->create();

    // Travel back another -25 days making the total 35 days
    $this->travel(-25)->days();
    Barstool::factory()->count(3)->create();

    assertDatabaseCount('barstools', 5);

    $this->travelBack();
    Artisan::call('model:prune', ['--model' => [Barstool::class]]);

    assertDatabaseCount('barstools', 2);

    config()->set('barstool.keep_for_days', 5);
    expect(config('barstool.keep_for_days'))->toBe(5);

    Artisan::call('model:prune', ['--model' => [Barstool::class]]);

    assertDatabaseEmpty('barstools');
});

it('does not log requests, responses or fatal on an excluded request', function () {
    config()->set('barstool.enabled', true);
    config()->set('barstool.ignore.requests', [RequestWithConnector::class]);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'John Wayne'],
                    ['name' => 'Billy the Kid'],
                ],
            ],
            status: 200,
        ),
        RequestWithConnector::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'Daisy Dot'],
                    ['name' => 'Pistol Pete'],
                ],
            ],
            status: 200,
        ),
    ]);

    $response = (new SoloUserRequest)->send();

    expect($response->status())->toBe(200);
    expect($response->json())->toBe([
        'data' => [
            ['name' => 'John Wayne'],
            ['name' => 'Billy the Kid'],
        ],
    ]);

    assertDatabaseCount('barstools', 1);

    $connector = new RandomConnector;
    $response = $connector->send(new RequestWithConnector);

    expect($response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->toBeNull();

    expect($response->status())->toBe(200);
    expect($response->json())->toBe([
        'data' => [
            ['name' => 'Daisy Dot'],
            ['name' => 'Pistol Pete'],
        ],
    ]);

    MockClient::global([
        RequestWithConnector::class => MockResponse::make(['error' => 'whoops'], 500)->throw(fn ($pendingRequest) => new FatalRequestException(new Exception, $pendingRequest)),
    ]);

    try {
        $connector = new RandomConnector;
        $connector->send(new RequestWithConnector);
    } catch (FatalRequestException $e) {
        expect($e->getPendingRequest()->headers()->get('X-Barstool-UUID'))->toBeNull();
    }

    assertDatabaseCount('barstools', 1);
});

it('does not log requests, responses or fatal on an excluded connector', function () {
    config()->set('barstool.enabled', true);
    config()->set('barstool.ignore.connectors', [NullConnector::class]);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'John Wayne'],
                    ['name' => 'Billy the Kid'],
                ],
            ],
            status: 200,
        ),
        RequestWithConnector::class => MockResponse::make(['error' => 'whoops'], 500)->throw(fn ($pendingRequest) => new FatalRequestException(new Exception, $pendingRequest)),
    ]);

    $response = (new SoloUserRequest)->send();

    expect($response->status())->toBe(200);
    expect($response->json())->toBe([
        'data' => [
            ['name' => 'John Wayne'],
            ['name' => 'Billy the Kid'],
        ],
    ]);

    assertDatabaseCount('barstools', 0);

    try {
        $connector = new RandomConnector;
        $connector->send(new RequestWithConnector);
    } catch (FatalRequestException $e) {
        expect($e->getPendingRequest()->headers()->get('X-Barstool-UUID'))->not()->toBeNull()->toBeString();
    }

    assertDatabaseCount('barstools', 1);

});

it('correctly records headers', function () {
    MockClient::global([
        RequestWithConnector::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'John Wayne'],
                    ['name' => 'Billy the Kid'],
                ],
            ],
            headers: ['token' => 'abc123'],
            status: 200,
        ),
    ]);

    $connector = new RandomConnector;
    $request = new RequestWithConnector;
    $request->headers()->add('some-secret', 'yeehaw');
    $response = $connector->send($request);

    expect($response->status())->toBe(200);
    expect($response->json())->toBe([
        'data' => [
            ['name' => 'John Wayne'],
            ['name' => 'Billy the Kid'],
        ],
    ]);
    expect($response->headers()->get('token'))->toBe('abc123');

    $request = $response->getPendingRequest();
    $requestHeaders = [
        'testing' => 'headers',
        'some-secret' => 'yeehaw',
        'X-Barstool-UUID' => $uuid = $request->headers()->get('X-Barstool-UUID'),
    ];
    expect($request->headers()->all())->toBe($requestHeaders);

    assertDatabaseCount('barstools', 1);

    $barstool = Barstool::where('uuid', $uuid)->sole();
    expect($barstool->request_headers)->toBe($requestHeaders);
    expect($response->headers()->all())->toBe(['token' => 'abc123']);
    expect($barstool->response_headers)->toBe(['token' => 'abc123']);
});

it('correctly records body, query, status & method', function () {
    MockClient::global([
        RequestWithConnector::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'John Wayne'],
                    ['name' => 'Billy the Kid'],
                ],
            ],
            headers: ['token' => 'abc123', 'Content-Type' => 'application/json'],
            status: 200,
        ),
        PostRequest::class => MockResponse::make(
            body: [],
            status: 201,
            headers: ['Content-Type' => 'application/json'],
        ),
        GetFileRequest::class => MockResponse::fixture('get-file'),
    ]);

    $connector = new RandomConnector;
    $request = new RequestWithConnector;
    $request->headers()->add('some-secret', 'yeehaw');
    $request->query()->add('page', 500);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->method->toBe('GET')
        ->url->toBe('https://tests.saloon.dev/api/user?page=500')
        ->request_headers->toBe([
            'testing' => 'headers',
            'some-secret' => 'yeehaw',
            'X-Barstool-UUID' => $barstool->uuid,
        ])
        ->request_body->toBeNull()
        ->response_status->toBe(200)
        ->successful->toBeTrue()
        ->response_headers->toBe(['token' => 'abc123', 'Content-Type' => 'application/json'])
        ->response_body->toBe(json_encode([
            'data' => [
                ['name' => 'John Wayne'],
                ['name' => 'Billy the Kid'],
            ],
        ]));

    $request = new PostRequest;
    $request->body()->set(fopen(__DIR__.'/yeehaw.txt', 'r'));
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();

    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(PostRequest::class)
        ->method->toBe('POST')
        ->url->toBe('https://craigpotter-not-real.dev/user')
        ->request_headers->toBe([
            'Content-Type' => 'text/plain',
            'X-Barstool-UUID' => $barstool->uuid,
        ])
        ->request_body->toBe('<Streamed Body>')
        ->response_status->toBe(201)
        ->successful->toBeTrue()
        ->response_headers->toBe(['Content-Type' => 'application/json'])
        ->response_body->toBe('[]');

    $request = new GetFileRequest;
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();

    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(GetFileRequest::class)
        ->method->toBe('GET')
        ->url->toBe('https://http.cat/images/418.jpg')
        ->request_headers->toBe([
            'X-Barstool-UUID' => $barstool->uuid,
        ])
        ->request_body->toBeEmpty()
        ->response_status->toBe(200)
        ->successful->toBeTrue()
        ->response_headers->toBe([
            'Age' => '68',
            'NEL' => '{"success_fraction":0,"report_to":"cf-nel","max_age":604800}',
            'Date' => 'Thu, 10 Oct 2024 19:17:35 GMT',
            'etag' => '"668d36cb-556f"',
            'CF-RAY' => '8d08f3915a8f60ef-LHR',
            'Server' => 'cloudflare',
            'alt-svc' => 'h3=":443"; ma=86400',
            'expires' => 'Thu, 31 Dec 2037 23:55:55 GMT',
            'Report-To' => '{"endpoints":[{"url":"https:\/\/a.nel.cloudflare.com\/report\/v4?s=lgJFDE1PZieCSO4IK5BWwV0I%2BNSvuveEgh11FqjElE3FcHg3kDkKmg29j8Y5tC4hGZZesZc7T8Gs8R53GcPR69G9ypdkimPz%2F2uPdYo1wBjfDxc%2FPIz9lwpZzw%3D%3D"}],"group":"cf-nel","max_age":604800}',
            'Connection' => 'keep-alive',
            'Content-Type' => 'image/jpeg',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'max-age=315360000',
            'last-modified' => 'Tue, 09 Jul 2024 13:10:35 GMT',
            'Content-Length' => '21871',
            'CF-Cache-Status' => 'HIT',
        ])
        ->response_body->toBe('<Unsupported Barstool Response Content>');
});

it('can exclude request headers for all request, certain headers,entire connectors or entire requests', function () {
    MockClient::global([
        RequestWithConnector::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'John Wayne'],
                    ['name' => 'Billy the Kid'],
                ],
            ],
            status: 200,
            headers: ['token' => 'abc123', 'Content-Type' => 'application/json'],
        ),
    ]);

    $connector = new RandomConnector;
    $request = new RequestWithConnector;
    $request->headers()->add('some-secret', 'yeehaw');

    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->request_headers->toBe([
            'testing' => 'headers',
            'some-secret' => 'yeehaw',
            'X-Barstool-UUID' => $barstool->uuid,
        ]);

    // Exclude all headers - should still get the X-Barstool-UUID
    config()->set('barstool.excluded_request_headers', ['*']);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->request_headers->toBe([
            'X-Barstool-UUID' => $barstool->uuid,
        ]);

    // Exclude 'some-secret' header
    config()->set('barstool.excluded_request_headers', ['some-secret']);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->request_headers->toBe([
            'testing' => 'headers',
            'some-secret' => 'REDACTED',
            'X-Barstool-UUID' => $barstool->uuid,
        ]);

    // Exclude the Request
    config()->set('barstool.excluded_request_headers', [RequestWithConnector::class]);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->request_headers->toBe([
            'X-Barstool-UUID' => $barstool->uuid,
        ]);

    // Exclude the connector
    config()->set('barstool.excluded_request_headers', [RandomConnector::class]);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->request_headers->toBe([
            'X-Barstool-UUID' => $barstool->uuid,
        ]);
});

it('can exclude response bodies for all responses, centire connectors or entire requests', function () {
    MockClient::global([
        RequestWithConnector::class => MockResponse::make(
            body: [
                'data' => [
                    ['name' => 'John Wayne'],
                    ['name' => 'Billy the Kid'],
                ],
            ],
            status: 200,
            headers: ['token' => 'abc123', 'Content-Type' => 'application/json'],
        ),
    ]);

    $connector = new RandomConnector;
    $request = new RequestWithConnector;

    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->response_body->toBe(json_encode([
            'data' => [
                ['name' => 'John Wayne'],
                ['name' => 'Billy the Kid'],
            ],
        ]));

    // Exclude all bodies - should still get the X-Barstool-UUID
    config()->set('barstool.excluded_response_body', ['*']);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->response_body->toBe('REDACTED');

    // Exclude the Request
    config()->set('barstool.excluded_response_body', [RequestWithConnector::class]);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->response_body->toBe('REDACTED');

    // Exclude the connector
    config()->set('barstool.excluded_response_body', [RandomConnector::class]);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->response_body->toBe('REDACTED');
});

it('can store response bodies where the kilobytes are below a config value', function () {
    $responseBody = ['data' => ['body' => Str::random(10000)]];
    $kilobytes = intdiv(mb_strlen(json_encode($responseBody)), 1000); // 10KB

    MockClient::global([
        RequestWithConnector::class => MockResponse::make(
            body: $responseBody,
            status: 200,
            headers: ['token' => 'abc123', 'Content-Type' => 'application/json'],
        ),
    ]);

    $connector = new RandomConnector;
    $request = new RequestWithConnector;

    $response = $connector->send($request);

    // Check the response body is stored when using the default config
    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->response_body->toBe(json_encode($responseBody));

    // Check the response body is not stored when the kilobytes are above the config value
    config()->set('barstool.max_response_size', $kilobytes - 1);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->response_body->toBe('<Unsupported Barstool Response Content>');

    // Check the response body is stored when the kilobytes are below the config value
    config()->set('barstool.max_response_size', $kilobytes + 1);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->response_body->toBe(json_encode($responseBody));

    // Check the response body is stored when the kilobytes are equal to the config value
    config()->set('barstool.max_response_size', $kilobytes);
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool)
        ->connector_class->toBe(RandomConnector::class)
        ->request_class->toBe(RequestWithConnector::class)
        ->response_body->toBe(json_encode($responseBody));

});

it('dispatches jobs when queue is enabled with correct payload', function () {
    Queue::fake();

    config()->set('barstool.enabled', true);
    config()->set('barstool.queue.enabled', true);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: ['data' => [['name' => 'John Wayne']]],
            status: 200,
            headers: ['Content-Type' => 'application/json'],
        ),
    ]);

    (new SoloUserRequest)->send();

    Queue::assertPushed(RecordBarstoolJob::class, 2);

    Queue::assertPushed(RecordBarstoolJob::class, function (RecordBarstoolJob $job) {
        return $job->type === RecordingType::REQUEST
            && $job->data['connector_class'] === NullConnector::class
            && $job->data['request_class'] === SoloUserRequest::class
            && $job->data['method'] === 'GET'
            && $job->data['url'] === 'https://tests.saloon.dev/api/user'
            && $job->data['successful'] === false;
    });

    Queue::assertPushed(RecordBarstoolJob::class, function (RecordBarstoolJob $job) {
        return $job->type === RecordingType::RESPONSE
            && $job->data['response_status'] === 200
            && $job->data['successful'] === true
            && $job->data['response_body'] === json_encode(['data' => [['name' => 'John Wayne']]])
            && array_key_exists('duration', $job->data);
    });

    assertDatabaseCount('barstools', 0);
});

it('dispatches jobs on the configured queue connection and name', function () {
    Queue::fake();

    config()->set('barstool.enabled', true);
    config()->set('barstool.queue.enabled', true);
    config()->set('barstool.queue.connection', 'redis');
    config()->set('barstool.queue.queue', 'barstool-recordings');

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: ['data' => [['name' => 'John Wayne']]],
            status: 200,
        ),
    ]);

    (new SoloUserRequest)->send();

    Queue::assertPushed(RecordBarstoolJob::class, function (RecordBarstoolJob $job) {
        return $job->connection === 'redis' && $job->queue === 'barstool-recordings';
    });
});

it('processes queued jobs and creates database records with correct data', function () {
    config()->set('barstool.enabled', true);
    config()->set('barstool.queue.enabled', true);
    config()->set('queue.default', 'sync');

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: ['data' => [['name' => 'John Wayne']]],
            status: 200,
            headers: ['Content-Type' => 'application/json'],
        ),
    ]);

    $response = (new SoloUserRequest)->send();

    assertDatabaseCount('barstools', 1);

    $uuid = $response->getPsrRequest()->getHeader('X-Barstool-UUID')[0];
    $barstool = Barstool::where('uuid', $uuid)->sole();

    expect($barstool)
        ->connector_class->toBe(NullConnector::class)
        ->request_class->toBe(SoloUserRequest::class)
        ->method->toBe('GET')
        ->url->toBe('https://tests.saloon.dev/api/user')
        ->response_status->toBe(200)
        ->successful->toBeTrue()
        ->response_body->toBe(json_encode(['data' => [['name' => 'John Wayne']]]))
        ->duration->not->toBeNull()
        ->request_headers->toBeArray()
        ->response_headers->toBeArray();
});

it('dispatches a fatal job when queue is enabled with correct payload', function () {
    Queue::fake();

    config()->set('barstool.enabled', true);
    config()->set('barstool.queue.enabled', true);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: ['error' => 'Something went wrong'],
            status: 500,
        )->throw(fn ($pendingRequest) => new FatalRequestException(new Exception('Fatal error'), $pendingRequest)),
    ]);

    try {
        (new SoloUserRequest)->send();
    } catch (FatalRequestException) {
        // Expected
    }

    Queue::assertPushed(RecordBarstoolJob::class, function (RecordBarstoolJob $job) {
        return $job->type === RecordingType::REQUEST
            && $job->data['connector_class'] === NullConnector::class
            && $job->data['request_class'] === SoloUserRequest::class;
    });

    Queue::assertPushed(RecordBarstoolJob::class, function (RecordBarstoolJob $job) {
        return $job->type === RecordingType::FATAL
            && $job->data['fatal_error'] === 'Fatal error'
            && $job->data['successful'] === false
            && $job->data['response_body'] === null
            && array_key_exists('duration', $job->data);
    });
});

it('does not dispatch jobs when queue is disabled', function () {
    Queue::fake();

    config()->set('barstool.enabled', true);
    config()->set('barstool.queue.enabled', false);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: ['data' => [['name' => 'John Wayne']]],
            status: 200,
        ),
    ]);

    (new SoloUserRequest)->send();

    Queue::assertNothingPushed();

    assertDatabaseCount('barstools', 1);
});

it('correctly records multipart request bodies', function () {
    config()->set('barstool.enabled', true);

    MockClient::global([
        MultipartPostRequest::class => MockResponse::make(
            body: ['success' => true],
            status: 200,
            headers: ['Content-Type' => 'application/json'],
        ),
    ]);

    $connector = new RandomConnector;
    $request = new MultipartPostRequest;
    $request->body()->add('file', 'file-contents', 'document.txt');
    $response = $connector->send($request);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();

    expect($barstool)
        ->request_class->toBe(MultipartPostRequest::class)
        ->method->toBe('POST')
        ->request_body->toBe('<Multipart Body>');
});

it('correctly records PUT, PATCH and DELETE request methods', function () {
    config()->set('barstool.enabled', true);

    MockClient::global([
        PutRequest::class => MockResponse::make(body: [], status: 200, headers: ['Content-Type' => 'application/json']),
        PatchRequest::class => MockResponse::make(body: [], status: 200, headers: ['Content-Type' => 'application/json']),
        DeleteRequest::class => MockResponse::make(body: [], status: 204, headers: ['Content-Type' => 'application/json']),
    ]);

    $connector = new RandomConnector;

    $putResponse = $connector->send(new PutRequest);
    $patchResponse = $connector->send(new PatchRequest);
    $deleteResponse = $connector->send(new DeleteRequest);

    $putBarstool = Barstool::where('uuid', $putResponse->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    $patchBarstool = Barstool::where('uuid', $patchResponse->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    $deleteBarstool = Barstool::where('uuid', $deleteResponse->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();

    expect($putBarstool)->method->toBe('PUT');
    expect($patchBarstool)->method->toBe('PATCH');
    expect($deleteBarstool)->method->toBe('DELETE');
});

it('records response body when Content-Type includes charset', function () {
    config()->set('barstool.enabled', true);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: ['name' => 'John Wayne'],
            status: 200,
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
        ),
    ]);

    $response = (new SoloUserRequest)->send();

    $barstool = Barstool::where('uuid', $response->getPsrRequest()->getHeader('X-Barstool-UUID')[0])->sole();

    expect($barstool)
        ->response_body->toBe(json_encode(['name' => 'John Wayne']))
        ->response_status->toBe(200);
});

it('does not record response body when Content-Type is missing', function () {
    config()->set('barstool.enabled', true);

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: 'some response',
            status: 200,
        ),
    ]);

    $response = (new SoloUserRequest)->send();

    $barstool = Barstool::where('uuid', $response->getPsrRequest()->getHeader('X-Barstool-UUID')[0])->sole();

    expect($barstool)->response_body->toBe('<Unsupported Barstool Response Content>');
});

it('does not affect response readability after recording', function () {
    config()->set('barstool.enabled', true);

    $expectedBody = ['data' => [['name' => 'John Wayne'], ['name' => 'Billy the Kid']]];

    MockClient::global([
        SoloUserRequest::class => MockResponse::make(
            body: $expectedBody,
            status: 200,
            headers: ['Content-Type' => 'application/json'],
        ),
    ]);

    $response = (new SoloUserRequest)->send();

    // Barstool has already recorded the response via middleware at this point.
    // Verify the response is still fully readable for consumer code.
    expect($response->body())->toBe(json_encode($expectedBody));
    expect($response->json())->toBe($expectedBody);
    expect($response->json('data'))->toBe($expectedBody['data']);
    expect($response->status())->toBe(200);

    // Reading multiple times should still work
    expect($response->body())->toBe(json_encode($expectedBody));
});

it('does not affect streamed request body for consumer code', function () {
    config()->set('barstool.enabled', true);

    MockClient::global([
        PostRequest::class => MockResponse::make(
            body: ['success' => true],
            status: 200,
            headers: ['Content-Type' => 'application/json'],
        ),
    ]);

    $connector = new RandomConnector;
    $request = new PostRequest;
    $stream = fopen(__DIR__.'/yeehaw.txt', 'r');
    $request->body()->set($stream);
    $response = $connector->send($request);

    // Barstool records '<Streamed Body>' without consuming the actual stream.
    // Verify the response is still fully readable.
    expect($response->json())->toBe(['success' => true]);
    expect($response->status())->toBe(200);

    $barstool = Barstool::where('uuid', $response->getPendingRequest()->headers()->get('X-Barstool-UUID'))->sole();
    expect($barstool->request_body)->toBe('<Streamed Body>');
});

it('generates unique job IDs based on UUID and recording type', function () {
    $requestJob = new RecordBarstoolJob(RecordingType::REQUEST, ['method' => 'GET'], 'test-uuid-123');
    $responseJob = new RecordBarstoolJob(RecordingType::RESPONSE, ['status' => 200], 'test-uuid-123');
    $fatalJob = new RecordBarstoolJob(RecordingType::FATAL, ['error' => 'fail'], 'test-uuid-123');

    expect($requestJob->uniqueId())->toBe('test-uuid-123-request');
    expect($responseJob->uniqueId())->toBe('test-uuid-123-response');
    expect($fatalJob->uniqueId())->toBe('test-uuid-123-fatal');

    // Same type + same UUID = same unique ID
    $duplicateJob = new RecordBarstoolJob(RecordingType::REQUEST, ['method' => 'POST'], 'test-uuid-123');
    expect($duplicateJob->uniqueId())->toBe($requestJob->uniqueId());

    // Different UUID = different unique ID
    $differentUuidJob = new RecordBarstoolJob(RecordingType::REQUEST, ['method' => 'GET'], 'other-uuid-456');
    expect($differentUuidJob->uniqueId())->not->toBe($requestJob->uniqueId());
});
