<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

class PresentUser
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Authenticatable $user): array
    {
        return [
            'id' => $user->getAuthIdentifier(),
            'display_name' => strtoupper((string) data_get($user, 'name')),
            'presented' => true,
        ];
    }
}
