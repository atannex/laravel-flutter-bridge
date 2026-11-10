<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature\Fortify;

use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\FortifyUser;
use Atannex\LaravelFlutterBridge\Tests\FortifyTestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Passkey;

class PasskeyManagementTest extends FortifyTestCase
{
    protected function addPasskey(FortifyUser $user, string $name, string $credentialId): Passkey
    {
        /** @var Passkey $passkey */
        $passkey = $user->passkeys()->create([
            'name' => $name,
            'credential_id' => $credentialId,
            'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
        ]);

        return $passkey;
    }

    public function test_list_rename_and_delete_passkeys(): void
    {
        Event::fake([PasskeyDeleted::class]);
        $user = $this->makeUser();
        $first = $this->addPasskey($user, 'MacBook', 'cred-1');
        $this->travel(1)->minutes();
        $second = $this->addPasskey($user, 'iPhone', 'cred-2');
        $second->forceFill(['last_used_at' => now()])->save();
        $token = $this->tokenFor();

        $this->asToken($token, 'GET', 'auth/user/passkeys')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.name', 'MacBook')
            ->assertJsonPath('data.0.last_used_at', null)
            ->assertJsonPath('data.1.name', 'iPhone')
            ->assertJsonStructure(['data' => [['id', 'name', 'authenticator', 'created_at', 'last_used_at']]]);

        $this->asToken($token, 'GET', 'auth/me')->assertJsonPath('data.user.passkeys_count', 2);

        $this->asToken($token, 'PATCH', 'auth/user/passkeys/'.$first->id, ['name' => '  Work laptop '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Work laptop');

        $this->asToken($token, 'PATCH', 'auth/user/passkeys/'.$first->id, ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed');

        // Removing one is sensitive.
        $this->asToken($token, 'DELETE', 'auth/user/passkeys/'.$first->id)
            ->assertStatus(423)
            ->assertJsonPath('meta.error_code', 'password_confirmation_required');

        $this->confirmPassword($token);

        $this->asToken($token, 'DELETE', 'auth/user/passkeys/'.$first->id)
            ->assertOk()
            ->assertJsonPath('data', ['deleted' => true]);

        Event::assertDispatched(PasskeyDeleted::class);
        $this->assertSame(1, $user->passkeys()->count());
    }

    public function test_another_users_passkey_is_not_found(): void
    {
        $this->makeUser();
        $john = $this->makeUser('john@example.test');
        $passkey = $this->addPasskey($john, 'John key', 'cred-john');
        $token = $this->tokenFor();
        $this->confirmPassword($token);

        $this->asToken($token, 'PATCH', 'auth/user/passkeys/'.$passkey->id, ['name' => 'Mine'])
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'not_found');
        $this->asToken($token, 'DELETE', 'auth/user/passkeys/'.$passkey->id)
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'not_found');

        $this->assertSame('John key', $passkey->fresh()->name);
    }

    public function test_delete_without_confirm_password_option(): void
    {
        config(['fortify.features' => [Features::passkeys(['confirmPassword' => false])]]);
        $user = $this->makeUser();
        $passkey = $this->addPasskey($user, 'Key', 'cred-1');

        $this->asToken($this->tokenFor(), 'DELETE', 'auth/user/passkeys/'.$passkey->id)->assertOk();
    }

    public function test_passkeys_follow_fortifys_feature(): void
    {
        config(['fortify.features' => [Features::registration()]]);
        $user = $this->makeUser();
        $passkey = $this->addPasskey($user, 'Key', 'cred-1');
        $token = $this->tokenFor();

        $this->asToken($token, 'GET', 'auth/user/passkeys')
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
        $this->asToken($token, 'PATCH', 'auth/user/passkeys/'.$passkey->id, ['name' => 'x'])
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
        $this->asToken($token, 'DELETE', 'auth/user/passkeys/'.$passkey->id)
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');

        $me = $this->asToken($token, 'GET', 'auth/me')->assertOk();
        $this->assertArrayNotHasKey('passkeys_count', (array) $me->json('data.user'));
    }
}
