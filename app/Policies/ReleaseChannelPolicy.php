<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\ReleaseChannel;
use App\Models\User;

final readonly class ReleaseChannelPolicy
{
    public function __construct(private ProductPolicy $products) {}

    public function view(User $user, ReleaseChannel $channel): bool
    {
        $product = $channel->product;

        return $product instanceof Product && $this->products->view($user, $product);
    }

    public function create(User $user, Product $product): bool
    {
        return $this->products->manageVersions($user, $product);
    }

    public function promote(User $user, ReleaseChannel $channel): bool
    {
        $product = $channel->product;

        return $channel->status === 'active'
            && $product instanceof Product
            && $this->products->publishVersions($user, $product);
    }
}
