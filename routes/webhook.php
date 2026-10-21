<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Samt\SkipCash\Http\Controllers\WebhookController;
use Samt\SkipCash\Http\Middleware\VerifyWebhookSignature;

/** @var array<int, string> $middleware */
$middleware = (array) config('skipcash.webhooks.middleware', []);

Route::post((string) config('skipcash.webhooks.path', 'skipcash/webhook'), WebhookController::class)
    ->middleware([...$middleware, VerifyWebhookSignature::class])
    ->name('skipcash.webhook');
