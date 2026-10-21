<?php

declare(strict_types=1);

namespace Samt\SkipCash\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use Samt\SkipCash\Exceptions\ApiException;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Payments\Transition;
use Samt\SkipCash\SkipCash;

/**
 * Re-queries payments that are still open locally and applies their real status.
 *
 * Catches every case where a webhook never arrived (network loss, deploy
 * window, misconfigured portal URL). Schedule it every 10–15 minutes:
 *
 *   Schedule::command('skipcash:reconcile')->everyFifteenMinutes();
 */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'skipcash:reconcile
        {--payment= : Reconcile one payment id regardless of age}
        {--stale= : Override stale_after_minutes}
        {--max-age= : Override max_age_hours}
        {--dry-run : Report without applying changes}';

    protected $description = 'Sync open SkipCash payments with the SkipCash API';

    public function handle(SkipCash $skipcash, Repository $config): int
    {
        $single = $this->option('payment');

        if (is_string($single) && $single !== '') {
            return $this->reconcileOne($skipcash, $single);
        }

        $stale = (int) ($this->option('stale') ?: $config->get('skipcash.reconcile.stale_after_minutes', 15));
        $maxAge = (int) ($this->option('max-age') ?: $config->get('skipcash.reconcile.max_age_hours', 72));
        $chunk = (int) $config->get('skipcash.reconcile.chunk', 100);
        $dryRun = (bool) $this->option('dry-run');

        $query = SkipCashPayment::query()
            ->open()
            ->where('created_at', '<=', Date::now()->subMinutes($stale))
            ->where('created_at', '>=', Date::now()->subHours($maxAge))
            ->orderBy('id');

        $counts = ['checked' => 0, 'applied' => 0, 'unchanged' => 0, 'rejected' => 0, 'errors' => 0];

        $query->chunkById($chunk, function ($payments) use ($skipcash, $dryRun, &$counts): void {
            foreach ($payments as $record) {
                $counts['checked']++;

                try {
                    if ($dryRun) {
                        $remote = $skipcash->payment($record->payment_id);
                        $this->line(sprintf('%s  local=%s  remote=%s', $record->payment_id, $record->status->label(), $remote->status->label()));

                        continue;
                    }

                    $transition = $skipcash->syncRecord($record);
                    $counts[$transition->value]++;

                    if ($transition === Transition::Applied) {
                        $this->info(sprintf('%s → %s', $record->payment_id, $record->status->label()));
                    }
                } catch (ApiException $e) {
                    $counts['errors']++;
                    $this->error(sprintf('%s  %s', $record->payment_id, $e->getMessage()));
                }
            }
        });

        $this->table(array_keys($counts), [array_values($counts)]);

        return $counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function reconcileOne(SkipCash $skipcash, string $paymentId): int
    {
        $record = SkipCashPayment::query()->where('payment_id', $paymentId)->first();

        if ($record === null) {
            $this->error("No local record for payment [{$paymentId}].");

            return self::FAILURE;
        }

        $transition = $skipcash->syncRecord($record);

        $this->info(sprintf('%s: %s (status now %s)', $paymentId, $transition->value, $record->refresh()->status->label()));

        return self::SUCCESS;
    }
}
