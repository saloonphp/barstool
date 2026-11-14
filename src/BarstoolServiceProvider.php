<?php

declare(strict_types=1);

namespace Saloon\Barstool;

use Throwable;
use Saloon\Config;
use Saloon\Http\Response;
use Saloon\Enums\PipeOrder;
use Saloon\Http\PendingRequest;
use Spatie\LaravelPackageTools\Package;
use Saloon\Exceptions\Request\FatalRequestException;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class BarstoolServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('barstool')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations([
                'create_barstools_table',
                'add_context_to_barstools_table',
                'add_created_at_index_to_barstools_table',
            ]);
    }

    public function packageRegistered(): void
    {
        Config::globalMiddleware()
            ->onFatalException(function (FatalRequestException $exception) {
                try {
                    if (Barstool::shouldRecord($exception) === false) {
                        return;
                    }

                    Barstool::record($exception);
                } catch (Throwable $recordingException) {
                    report($recordingException);
                }
            }, order: PipeOrder::FIRST)
            ->onRequest(function (PendingRequest $request) {
                try {
                    if (Barstool::shouldRecord($request) === false) {
                        return;
                    }

                    $request->config()->add(
                        'barstool-request-time',
                        microtime(true) * 1000
                    );

                    Barstool::record($request);
                } catch (Throwable $recordingException) {
                    report($recordingException);
                }
            })
            ->onResponse(function (Response $response) {
                try {
                    if (Barstool::shouldRecord($response) === false) {
                        return;
                    }

                    $response->getPendingRequest()->config()->add(
                        'barstool-response-time',
                        microtime(true) * 1000
                    );

                    Barstool::record($response);
                } catch (Throwable $recordingException) {
                    report($recordingException);
                }
            });
    }
}
