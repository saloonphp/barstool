# Barstool

[![Latest Version on Packagist](https://img.shields.io/packagist/v/saloonphp/barstool.svg?style=flat-square)](https://packagist.org/packages/saloonphp/barstool)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/saloonphp/barstool/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/saloonphp/barstool/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/saloonphp/barstool/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/saloonphp/barstool/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/saloonphp/barstool.svg?style=flat-square)](https://packagist.org/packages/saloonphp/barstool)

Barstool is a dedicated Laravel package to help you keep track of your [Saloon](https://github.com/saloonphp/saloon) requests & responses.

Barstool will allow you to easily view, search, and filter your logs directly in your database tool of choice.

The package is designed to be as simple as possible to get up and running, with minimal configuration required.

So pull up a barstool, grab a drink, and let's get logging in the Saloon! Yeehaw!

## Requirements

- PHP 8.3+
- Laravel 12+
- Saloon v4

## Installation

You can install the package via composer:

```bash
composer require saloonphp/barstool
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="barstool-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="barstool-config"
```

Finally, set up [pruning](#pruning-old-recordings) in your scheduler so your logs don't grow forever.

## Usage

That's all folks!
Once installed, Barstool starts logging your [Saloon](https://github.com/saloonphp/saloon) requests automatically.
Check the config out for more control.

Here are some of the things you can see with Barstool:
- Request Method
- Connector Used
- Request Used
- Request URL
- Request Headers
- Request Body
- Response Status Code
- Response Headers
- Response Body
- Response Duration

Barstool will even log fatal errors caused by your Saloon requests, so you can see what went wrong.
<p><img src="/art/fatal_error.png" alt="Screenshot of the fatal error logged in the database"></p>

> [!TIP]
> We will be adding more features soon, so keep an eye out for updates!

## Configuration

Everything below lives in `config/barstool.php` once published.

### Enabling & disabling

Barstool is enabled out of the box. To switch it off entirely — no requests recorded — set the env variable:

```dotenv
BARSTOOL_ENABLED=false
```

Handy for local development or test environments where you don't want the noise.

### Choosing what gets recorded

By default, Barstool records every Saloon request. You can narrow that down in two directions:

- **`only`** — an allowlist. If any connectors or requests are listed, only those are recorded and everything else is skipped automatically. Handy when you have lots of connectors but only care about a few.
- **`ignore`** — a denylist. Listed connectors or requests are never recorded.

```php
// config/barstool.php
'only' => [
    'connectors' => [
        StripeConnector::class, // record everything sent through this connector...
    ],
    'requests' => [],
],

'ignore' => [
    'connectors' => [],
    'requests' => [
        StripeHealthCheckRequest::class, // ...except this noisy request
    ],
],
```

A request is recorded if it matches either `only` list (or both lists are empty), and the `ignore` list always takes precedence — so you can allow a whole connector and still ignore individual requests on it.

### Keeping only failed responses

If you mainly use Barstool to investigate failures, you can skip storing successful response data:

```php
'keep_successful_responses' => false,
```

The request itself is still recorded — you just won't get the response body, headers, and status for successful calls. Failed responses and fatal errors are always kept.

### Redacting sensitive request headers

Headers listed in `excluded_request_headers` are stored with their value replaced by `REDACTED`. The `Authorization` header is redacted by default.

```php
'excluded_request_headers' => [
    'Authorization',
    'X-Api-Key',            // redact this header on every request
    SensitiveRequest::class, // redact ALL headers for this request
    SensitiveConnector::class, // redact ALL headers for this connector
    // '*',                 // redact ALL headers on every request
],
```

When `'*'` or a connector/request class matches, every header is dropped from the recording except Barstool's own `X-Barstool-UUID` correlation header.

### Excluding response bodies

Response bodies for sensitive endpoints can be kept out of the database entirely — they are stored as `REDACTED`:

```php
'excluded_response_body' => [
    SensitiveConnector::class, // exclude bodies for a whole connector
    SensitiveRequest::class,   // or a single request
    // '*',                    // or every response
],
```

### Response body limits

To keep the table lean, Barstool only stores response bodies that are:

- **A supported content type** — JSON, XML, SOAP, HTML, or plain text. Anything else (files, images, binary data) is stored as `<Unsupported Barstool Response Content>`.
- **Within the size limit** — `max_response_size` (in kilobytes, default `100`). Oversized bodies are stored as `<Unsupported Barstool Response Content>` too.

```php
'max_response_size' => 100,
```

You may also spot a couple of other placeholder values in the `barstools` table: streamed request/response bodies are stored as `<Streamed Body>` (reading them would consume the stream before your application gets it), and multipart request bodies as `<Multipart Body>`.

### Database connection

Recordings are stored on your default database connection. To keep them elsewhere — a separate database, or just a different connection — set:

```dotenv
BARSTOOL_DB_CONNECTION=barstool
```

The migration and the `Barstool` model both respect this connection.

### Pruning old recordings

Recordings are kept for 30 days by default, controlled by:

```php
'keep_for_days' => 30,
```

Pruning uses [Laravel's model pruning](https://laravel.com/docs/eloquent#pruning-models), so you need to schedule it. Please check the Laravel Documentation for your version to know where to put the code below.

```php
use Saloon\Barstool\Models\Barstool;

Schedule::command('model:prune', [
    '--model' => [Barstool::class],
])->daily();
```

### Queue support

By default, Barstool writes recordings to the database synchronously. If you'd like to offload this to a queue, you can enable it in the config:

```php
'queue' => [
    'enabled' => env('BARSTOOL_QUEUE_ENABLED', false),
    'connection' => env('BARSTOOL_QUEUE_CONNECTION'),  // null uses default connection
    'queue' => env('BARSTOOL_QUEUE_NAME'),             // null uses default queue
],
```

Or simply set `BARSTOOL_QUEUE_ENABLED=true` in your `.env` file.

When queue support is enabled, recordings are dispatched as jobs instead of being written inline. Each job is **unique** (preventing duplicates) and uses **idempotent writes** (`updateOrCreate`), so recordings are safe even if a job is retried. Failed jobs will automatically retry up to 3 times with a backoff of 5 and 30 seconds.

## Adding context to recordings

Sometimes the request and response alone don't tell the whole story. You can attach your own context to recordings — the current user, tenant, job name, anything you like — and it will be stored in the `context` column of the `barstools` table as JSON:

```php
use Saloon\Barstool\Barstool;

Barstool::context([
    'user_id' => auth()->id(),
    'tenant_id' => $tenant->id,
]);

// Or add a single key:
Barstool::addContext('job', 'user-sync');
```

Once set, the context is stored against every request Barstool records until the end of the current request or job. Calling `context()` again merges the new keys in (overwriting any that already exist), and you can clear everything with `Barstool::flushContext()`.

Under the hood this uses [Laravel Context](https://laravel.com/docs/context) hidden data, which means:

- Context added in a controller is carried into queued jobs automatically, so requests sent from inside a job still record it.
- It is reset between requests and jobs by the framework, so nothing leaks across tenants or users.
- It stays out of your application's log context.

> [!IMPORTANT]
> If you are upgrading from an earlier version of Barstool, publish and run the migrations again to add the new `context` column:
> ```bash
> php artisan vendor:publish --tag="barstool-migrations"
> php artisan migrate
> ```
> Barstool only touches the `context` column when you actually set context, so upgrading the package without running the migration is safe until you start using this feature.

## Correlating Barstool records with your own models

If you want a row in one of your own tables to point at a Barstool recording (rather than storing extra data on the recording itself), you can read the recording's UUID straight off the sent request. Barstool adds an `X-Barstool-UUID` header to every request it records:

```php
$response = $connector->send($request);

$barstoolUuid = $response->getPendingRequest()->headers()->get('X-Barstool-UUID');

if ($response->failed()) {
    UserSyncLog::create([
        'user_id' => auth()->id(),
        'barstool_uuid' => $barstoolUuid,
    ]);
}
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](./.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

If you discover any security related issues, please email barstool@craigpotter.co.uk instead of using the issue tracker.

## Credits

- [Craig Potter](https://github.com/craigpotter)
- [Sam Carre](https://github.com/Sammyjo20)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
