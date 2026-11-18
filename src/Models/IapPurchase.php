<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Models;

use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Enums\ProductType;
use BijayX\IapGuard\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Read model over the iap_purchases table for reporting and app queries.
 *
 * Write through IapGuard (claim, notifications, releaseUser) only: the store relies on the
 * version column for its atomic updates.
 *
 * @property int $id
 * @property Platform $platform
 * @property string $key
 * @property string|null $user_id
 * @property string $product_id
 * @property ProductType $type
 * @property PurchaseStatus $status
 * @property Environment $environment
 * @property string|null $transaction_id
 * @property string|null $original_transaction_id
 * @property string|null $purchase_token
 * @property string|null $account_token
 * @property Carbon|null $purchased_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $grace_period_expires_at
 * @property Carbon|null $revoked_at
 * @property bool|null $will_renew
 * @property bool $family_shared
 * @property array<string, mixed> $purchase
 * @property Carbon $last_event_at
 * @property Carbon|null $claimed_at
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IapPurchase extends Model
{
    protected $table = 'iap_purchases';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function getConnectionName(): ?string
    {
        $connection = config('iap-guard.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'type' => ProductType::class,
            'status' => PurchaseStatus::class,
            'environment' => Environment::class,
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_period_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_event_at' => 'datetime',
            'claimed_at' => 'datetime',
            'will_renew' => 'boolean',
            'family_shared' => 'boolean',
            'purchase' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * The normalized purchase stored for this record.
     */
    public function toPurchase(): Purchase
    {
        return Purchase::fromArray($this->purchase);
    }

    public function isEntitled(?\DateTimeInterface $now = null): bool
    {
        return $this->toPurchase()->isEntitled($now);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForUser(Builder $query, string|int $userId): void
    {
        $query->where('user_id', (string) $userId);
    }

    /**
     * Rows whose stored status may grant access (time-based expiry is checked by isEntitled()).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [PurchaseStatus::Active->value, PurchaseStatus::GracePeriod->value]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePlatform(Builder $query, Platform $platform): void
    {
        $query->where('platform', $platform->value);
    }
}
