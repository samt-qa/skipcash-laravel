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
            $table->uuid('payment_id')->unique();
            $table->string('transaction_id', 40)->nullable()->index();
            $table->unsignedTinyInteger('status')->default(0)->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('QAR');
            $table->string('environment', 10);
            $table->text('pay_url')->nullable();
            $table->string('visa_id', 64)->nullable()->index();
            $table->string('token_id', 64)->nullable();
            $table->string('card_type', 32)->nullable();
            $table->string('card_number', 32)->nullable();
            $table->uuid('recurring_subscription_id')->nullable()->index();
            $table->string('refund_id', 64)->nullable();
            $table->unsignedTinyInteger('refund_status')->nullable();
            $table->json('custom')->nullable();
            $table->nullableMorphs('payable');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('skipcash.database.payments_table', 'skipcash_payments');
    }
};
