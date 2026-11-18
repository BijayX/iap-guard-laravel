<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Tests\Support;

use BijayX\IapGuard\Concerns\HasPurchases;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal app user model for the HasPurchases tests (also lets PHPStan analyse the trait).
 *
 * @property int $id
 * @property string $name
 */
class User extends Model
{
    use HasPurchases;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
