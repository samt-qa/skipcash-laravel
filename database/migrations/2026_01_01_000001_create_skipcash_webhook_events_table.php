<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('skipcash.database.connection');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('payment_id');
            $table->unsignedTinyInteger('status_id');
            // Status the record had before this delivery was applied; set only when the
            // transition committed, so a retry can replay the events without re-applying.
            $table->unsignedTinyInteger('from_status_id')->nullable();
            $table->string('transaction_id', 40)->nullable()->index();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();

            // Deduplication is decided by the processor (a redelivery that would not
            // change the record), not by a unique key: the same status can legitimately
            // arrive twice, e.g. "pending refund" for a second partial refund.
            $table->index(['payment_id', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('skipcash.database.webhook_events_table', 'skipcash_webhook_events');
    }
};
