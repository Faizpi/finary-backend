<?php

namespace App\Contracts;

use App\Models\User;

interface UserRegistrationContract
{
    /**
     * Register a new user, seed their onboarding assessment, and issue an
     * API token. Runs inside a transaction.
     *
     * @param  array<string, mixed>  $validated  Validated registration payload.
     * @return array{user: User, token: string}
     */
    public function register(array $validated): array;
}
