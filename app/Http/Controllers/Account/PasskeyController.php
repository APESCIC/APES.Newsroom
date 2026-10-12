<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Models\User;
use App\Notifications\Security\PasskeyAddedNotification;
use App\Notifications\Security\PasskeyRemovedNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Passkeys\PasskeyCeremony;
use App\Services\Auth\Passkeys\PasskeyRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Passkeys for password and magic-link accounts. Staff sign in through
 * Cloudron SSO and cannot add passkeys here.
 */
class PasskeyController extends Controller
{
    public const MAX_PASSKEYS = 20;

    public function __construct(
        private readonly PasskeyCeremony $ceremony,
        private readonly AuditLogger $audit,
    ) {}

    public function options(Request $request): Response
    {
        $user = $this->eligibleUser($request);

        if ($user->passkeys()->count() >= self::MAX_PASSKEYS) {
            abort(422, 'You have reached the limit of '.self::MAX_PASSKEYS.' passkeys. Remove one before adding another.');
        }

        return response($this->ceremony->registrationOptions($request, $user), 200, ['Content-Type' => 'application/json']);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->eligibleUser($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'credential' => ['required', 'string', 'max:16000'],
        ]);

        if ($user->passkeys()->count() >= self::MAX_PASSKEYS) {
            throw ValidationException::withMessages(['passkey' => 'You have reached the limit of '.self::MAX_PASSKEYS.' passkeys.']);
        }

        try {
            $passkey = $this->ceremony->register($request, $user, $validated['credential'], trim($validated['name']));
        } catch (PasskeyRejected $exception) {
            Log::notice('Passkey registration rejected', [
                'user_id' => $user->getKey(),
                'reason' => ($exception->getPrevious() ?? $exception)->getMessage(),
            ]);

            throw ValidationException::withMessages(['passkey' => 'That passkey could not be added. Please try again.']);
        }

        $this->audit->record($user, 'account.passkey_added', $user, [
            'passkey_id' => $passkey->getKey(),
            'name' => $passkey->name,
        ], $request);
        $user->notify(new PasskeyAddedNotification($passkey->name));

        return redirect()->route('account.security')->with('status', 'passkey-added');
    }

    public function update(Request $request, int $passkey): RedirectResponse
    {
        $user = $this->eligibleUser($request);
        $model = $this->ownedPasskey($user, $passkey);

        $validated = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $previous = $model->name;
        $model->update(['name' => trim($validated['name'])]);

        $this->audit->record($user, 'account.passkey_renamed', $user, [
            'passkey_id' => $model->getKey(),
            'from' => $previous,
            'to' => $model->name,
        ], $request);

        return redirect()->route('account.security')->with('status', 'passkey-renamed');
    }

    public function destroy(Request $request, int $passkey): RedirectResponse
    {
        $user = $this->eligibleUser($request);
        $model = $this->ownedPasskey($user, $passkey);

        $model->delete();

        $this->audit->record($user, 'account.passkey_removed', $user, [
            'passkey_id' => $model->getKey(),
            'name' => $model->name,
        ], $request);
        $user->notify(new PasskeyRemovedNotification($model->name));

        return redirect()->route('account.security')->with('status', 'passkey-removed');
    }

    private function eligibleUser(Request $request): User
    {
        $user = $request->user();
        abort_if($user->requiresSso(), 403);

        return $user;
    }

    private function ownedPasskey(User $user, int $id): Passkey
    {
        /** @var Passkey */
        return $user->passkeys()->whereKey($id)->firstOrFail();
    }
}
