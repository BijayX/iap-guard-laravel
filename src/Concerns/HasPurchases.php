<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Concerns;

use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\IapGuard;
use BijayX\IapGuard\Models\IapPurchase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Add to your User model:
 *
 *     use BijayX\IapGuard\Concerns\HasPurchases;
 *
 *     class User extends Authenticatable { use HasPurchases; }
 *
 * iap_purchases.user_id is a string column holding (string) $user->getKey().
 *
 * @phpstan-require-extends Model
 */
trait HasPurchases
{
    /**
     * Every purchase this user owns (any status).
     *
     * @return HasMany<IapPurchase, $this>
     */
    public function iapPurchases(): HasMany
    {
        return $this->hasMany(IapPurchase::class, 'user_id', $this->getKeyName());
    }

    /**
     * Purchases that grant access right now, across both stores.
     *
     * @return list<Purchase>
     */
    public function iapEntitlements(): array
    {
        return app(IapGuard::class)->entitlements($this->iapUserId());
    }

    /**
     * Whether any current entitlement covers $productId (Google bundles included).
     */
    public function isEntitledTo(string $productId): bool
    {
        foreach ($this->iapEntitlements() as $purchase) {
            if ($purchase->isFor($productId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The id used as userId with IapGuard. Override to use something other than the primary key.
     */
    public function iapUserId(): string
    {
        $key = $this->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
