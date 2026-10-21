<?php

declare(strict_types=1);

namespace Samt\SkipCash;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Samt\SkipCash\Client\SkipCashClient;
use Samt\SkipCash\Console\ReconcilePaymentsCommand;
use Samt\SkipCash\Credentials\ConfigCredentialsResolver;
use Samt\SkipCash\Credentials\CredentialsResolver;
use Samt\SkipCash\Payments\PaymentRecorder;
use Samt\SkipCash\Requests\CreatePayment;
use Samt\SkipCash\Signing\Signer;
use Samt\SkipCash\Webhooks\WebhookProcessor;
use Samt\SkipCash\Webhooks\WebhookVerifier;

final class SkipCashServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigRecursively(__DIR__.'/../config/skipcash.php', 'skipcash');

        $this->app->bind(CredentialsResolver::class, ConfigCredentialsResolver::class);

        $this->app->singleton(Signer::class);

        $this->app->singleton(SkipCashClient::class, function (Application $app): SkipCashClient {
            $config = $this->config();

            /** @var array{sandbox: string, production: string} $urls */
            $urls = (array) $config->get('skipcash.urls');

            /** @var array{timeout: int, connect_timeout: int, read_retries: int} $http */
            $http = (array) $config->get('skipcash.http');

            return new SkipCashClient(
                container: $app,
                credentials: $app->make(CredentialsResolver::class),
                signer: $app->make(Signer::class),
                baseUrls: $urls,
                http: $http,
            );
        });

        $this->app->singleton(PaymentRecorder::class, fn (Application $app) => new PaymentRecorder(
            $app,
            $this->logger($app),
        ));

        $this->app->singleton(WebhookVerifier::class);

        $this->app->singleton(WebhookProcessor::class, fn (Application $app) => new WebhookProcessor(
            $app->make(PaymentRecorder::class),
            $app->make(SkipCashClient::class),
            $app->make('db'),
            $app,
            $this->config(),
            $this->logger($app),
        ));

        $this->app->singleton(SkipCash::class);
        $this->app->alias(SkipCash::class, 'skipcash');
    }

    public function boot(): void
    {
        $config = $this->config();

        CreatePayment::useDefaults(
            (string) $config->get('skipcash.defaults.local_country_code', '+974'),
            (string) $config->get('skipcash.defaults.fallback_email_domain', 'customers.example.com'),
        );

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/skipcash.php' => $this->app->configPath('skipcash.php'),
            ], 'skipcash-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'skipcash-migrations');

            $this->commands([ReconcilePaymentsCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ((bool) $config->get('skipcash.webhooks.register_route', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        }
    }

    /**
     * Like mergeConfigFrom(), but nested: an app that overrides only
     * `skipcash.webhooks.queue` keeps every other `skipcash.webhooks.*` default.
     * Lists (e.g. middleware) are replaced wholesale, never merged index by index.
     */
    private function mergeConfigRecursively(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->config();

        /** @var array<string, mixed> $defaults */
        $defaults = require $path;

        /** @var array<string, mixed> $overrides */
        $overrides = (array) $config->get($key, []);

        $config->set($key, self::merge($defaults, $overrides));
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $overrides
     * @return array<array-key, mixed>
     */
    private static function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $k => $value) {
            $defaults[$k] = is_array($value) && isset($defaults[$k]) && is_array($defaults[$k])
                && ! array_is_list($value) && ! array_is_list($defaults[$k])
                    ? self::merge($defaults[$k], $value)
                    : $value;
        }

        return $defaults;
    }

    private function config(): Repository
    {
        /** @var Repository */
        return $this->app->make('config');
    }

    private function logger(Application $app): LoggerInterface
    {
        $channel = $this->config()->get('skipcash.logging.channel');

        /** @var LogManager $log */
        $log = $app->make('log');

        return is_string($channel) && $channel !== '' ? $log->channel($channel) : $log;
    }
}
