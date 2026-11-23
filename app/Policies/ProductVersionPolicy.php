<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductVersion;
use App\Models\User;

final readonly class ProductVersionPolicy
{
    public function __construct(private ProductPolicy $products) {}

    public function view(User $user, ProductVersion $version): bool
    {
        return $this->products->view($user, $version->product);
    }

    public function update(User $user, ProductVersion $version): bool
    {
        return $version->status === 'draft'
            && $this->products->manageVersions($user, $version->product);
    }

    public function publish(User $user, ProductVersion $version): bool
    {
        return $version->status === 'draft'
            && $this->products->publishVersions($user, $version->product);
    }
}
