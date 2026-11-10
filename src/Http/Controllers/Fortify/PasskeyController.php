<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers\Fortify;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\InteractsWithFortify;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Support\Payload;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Passkeys\Passkey;

/**
 * Passkey management (laravel/passkeys via Fortify's passkeys feature).
 *
 * Creating a passkey and signing in with one need the platform credential
 * APIs on a verified domain; the app lists, renames and removes them.
 */
class PasskeyController extends Controller
{
    use InteractsWithFortify;

    protected const DELETE = 'Laravel\\Passkeys\\Actions\\DeletePasskey';

    /**
     * GET auth/user/passkeys.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->passkeyUser($request);

        $passkeys = method_exists($user, 'passkeys')
            ? $user->passkeys()->orderBy('created_at')->orderBy('id')->get()
            : collect();

        return FlutterResponse::success(
            $passkeys->map(fn (Model $passkey) => $this->present($passkey))->values()->all(),
            __('Passkeys retrieved.')
        );
    }

    /**
     * PATCH auth/user/passkeys/{passkey}: rename.
     */
    public function update(Request $request, string $passkey): JsonResponse
    {
        $user = $this->passkeyUser($request);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $model = $this->find($user, $passkey);
        $model->forceFill(['name' => trim((string) $validated['name'])])->save();

        return FlutterResponse::success($this->present($model), __('Passkey renamed.'));
    }

    /**
     * DELETE auth/user/passkeys/{passkey} (PasskeyDeleted event).
     */
    public function destroy(Request $request, string $passkey): JsonResponse
    {
        $user = $this->passkeyUser($request);
        $model = $this->find($user, $passkey);

        $this->ensurePasswordConfirmed($request, $user, 'passkeys');

        app(self::DELETE)($user, $model);

        return FlutterResponse::success(['deleted' => true], __('Passkey deleted.'));
    }

    protected function passkeyUser(Request $request): Model&Authenticatable
    {
        $this->fortify()->ensureEnabled('passkeys');
        $user = $this->currentUser($request);

        if (! $this->fortify()->userHasPasskeys($user)) {
            throw FlutterBridgeException::fortifyFeatureDisabled('passkeys');
        }

        return $user;
    }

    /**
     * One of the user's passkeys, or 404 not_found.
     */
    protected function find(Model $user, string $id): Passkey
    {
        $passkey = method_exists($user, 'passkeys') ? $user->passkeys()->whereKey($id)->first() : null;

        if (! $passkey instanceof Passkey) {
            abort(404, __('Passkey not found.'));
        }

        return $passkey;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Model $passkey): array
    {
        return [
            'id' => $passkey->getKey(),
            'name' => $passkey->getAttribute('name'),
            'authenticator' => $passkey->getAttribute('authenticator'),
            'created_at' => Payload::date($passkey->getAttribute('created_at')),
            'last_used_at' => Payload::date($passkey->getAttribute('last_used_at')),
        ];
    }
}
