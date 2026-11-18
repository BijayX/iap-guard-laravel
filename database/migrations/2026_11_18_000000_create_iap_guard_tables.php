<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('iap-guard.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::create('iap_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16);
            // Ownership key: Apple originalTransactionId (transactionId for consumables),
            // Google first purchase token of the chain.
            $table->string('key', 512);
            $table->string('user_id', 191)->nullable()->index();

            // Promoted from the purchase JSON for querying. The JSON column is the source of truth.
            $table->string('product_id', 191)->index();
            $table->string('type', 32);
            $table->string('status', 32)->index();
            $table->string('environment', 16);
            $table->string('transaction_id', 191)->nullable();
            $table->string('original_transaction_id', 191)->nullable();
            $table->string('purchase_token', 512)->nullable();
            $table->string('account_token', 191)->nullable();
            $table->dateTime('purchased_at', 3)->nullable();
            $table->dateTime('expires_at', 3)->nullable()->index();
            $table->dateTime('grace_period_expires_at', 3)->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->boolean('will_renew')->nullable();
            $table->boolean('family_shared')->default(false);

            $table->json('purchase');
            $table->dateTime('last_event_at', 3);
            $table->dateTime('claimed_at', 3)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['platform', 'key']);
        });

        Schema::create('iap_purchase_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('iap_purchases')->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('token', 512);

            $table->unique(['platform', 'token']);
        });

        Schema::create('iap_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16);
            $table->string('notification_id', 191);
            $table->dateTime('created_at', 3)->nullable()->index();

            $table->unique(['platform', 'notification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iap_notifications');
        Schema::dropIfExists('iap_purchase_tokens');
        Schema::dropIfExists('iap_purchases');
    }
};
