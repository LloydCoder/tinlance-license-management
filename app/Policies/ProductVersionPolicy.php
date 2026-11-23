<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;

final readonly class ProductVersionPolicy
{
    public function __construct(private ProductPolicy $products) {}

    public function view(User $user, ProductVersion $version): bool
    {
        $product = $version->product;

        return $product instanceof Product && $this->products->view($user, $product);
    }

    public function update(User $user, ProductVersion $version): bool
    {
        $product = $version->product;

        return $version->status === 'draft'
            && $product instanceof Product
            && $this->products->manageVersions($user, $product);
    }

    public function publish(User $user, ProductVersion $version): bool
    {
        $product = $version->product;

        return $version->status === 'draft'
            && $product instanceof Product
            && $this->products->publishVersions($user, $product);
    }
}
