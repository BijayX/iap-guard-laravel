<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Support;

use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Exceptions\PolicyException;

/**
 * Optional allowlist of product ids shared by both platforms (SPEC §7.1, config "products").
 *
 * @internal
 */
final readonly class ProductAllowlist
{
    /**
     * @param  list<string>|null  $products  null = allow every product.
     */
    public function __construct(private ?array $products) {}

    /**
     * Every product of the purchase (Google bundles can have several) must be allowed.
     *
     * @throws PolicyException
     */
    public function check(Purchase $purchase): void
    {
        if ($this->products === null) {
            return;
        }

        foreach ($purchase->productIds === [] ? [$purchase->productId] : $purchase->productIds as $productId) {
            if (! in_array($productId, $this->products, true)) {
                throw PolicyException::unknownProduct($purchase->platform, $productId);
            }
        }
    }
}
