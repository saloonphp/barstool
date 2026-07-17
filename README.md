# Barstool

[![Latest Version on Packagist](https://img.shields.io/packagist/v/saloonphp/barstool.svg?style=flat-square)](https://packagist.org/packages/saloonphp/barstool)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/saloonphp/barstool/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/saloonphp/barstool/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/saloonphp/barstool/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/saloonphp/barstool/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/saloonphp/barstool.svg?style=flat-square)](https://packagist.org/packages/saloonphp/barstool)

Barstool is a dedicated Laravel package to help you keep track of your [Saloon](https://github.com/saloonphp/saloon) requests & responses.

Barstool will allow you to easily view, search, and filter your logs directly in your database tool of choice.

The package is designed to be as simple as possible to get up and running, with minimal configuration required.

So pull up a barstool, grab a drink, and let's get logging in the Saloon! Yeehaw!

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

You should also set up Laravel Model Pruning in your scheduler. Please check the Laravel Documentation for your version to know where to put the code below.
```php
use Saloon\Barstool\Models\Barstool;


Schedule::command('model:prune', [
    '--model' => [Barstool::class],
])->daily();
```

## Usage

That's all folks!
Once installed, it will start logging your [Saloon](https://github.com/saloonphp/saloon) requests automatically.
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

The logging will even log fatal errors caused by your saloon requests so you can see what went wrong.
<p><img src="/art/fatal_error.png" alt="Screenshot of the fatal error logged in the database"></p>

> [!TIP]
> We will be adding more features soon, so keep an eye out for updates!

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

## Queue Support

By default, Barstool writes recordings to the database synchronously. If you'd like to offload this to a queue, you can enable it in the config:

```php
// config/barstool.php
'queue' => [
    'enabled' => env('BARSTOOL_QUEUE_ENABLED', false),
    'connection' => env('BARSTOOL_QUEUE_CONNECTION'),  // null uses default connection
    'queue' => env('BARSTOOL_QUEUE_NAME'),             // null uses default queue
],
```

Or simply set `BARSTOOL_QUEUE_ENABLED=true` in your `.env` file.

When queue support is enabled, recordings are dispatched as jobs instead of being written inline. Each job is **unique** (preventing duplicates) and uses **idempotent writes** (`updateOrCreate`), so recordings are safe even if a job is retried. Failed jobs will automatically retry up to 3 times with a backoff of 5 and 30 seconds.


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
