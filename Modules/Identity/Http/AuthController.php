<?php

namespace Modules\Identity\Http;

use App\Enums\UserStatus;
use App\Models\User;
use Core\Audit\Audit;
use Core\Http\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Settings\Settings;

class AuthController
{
    public function register(Request $request, Settings $settings): JsonResponse
    {
        abort_unless($settings->get('auth.registration_enabled'), 403, __('api.auth.registration_disabled'));
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        $user = User::query()->create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
        $user->assignRole('user');
        $user->sendEmailVerificationNotification();
        Audit::record('auth.registered', $user);

        return ApiResponse::success(['message' => __('api.auth.registered')], 201);
    }

    public function login(Request $request, Settings $settings): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:100']]);
        $email = Str::lower($credentials['email']);
        $key = 'login:'.sha1($email.'|'.$request->ip());
        $maxAttempts = (int) $settings->get('auth.max_login_attempts');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return ApiResponse::error(__('api.auth.too_many_attempts'), 429, code: 'auth.rate_limited');
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            Audit::record('auth.login_failed', $user, ['email' => $email]);

            return ApiResponse::error(__('api.auth.invalid_credentials'), 422, ['email' => [__('api.auth.invalid_credentials')]], 'auth.invalid_credentials');
        }

        if ($user->status !== UserStatus::Active) {
            Audit::record('auth.login_blocked', $user);

            return ApiResponse::error(__('api.auth.suspended'), 403, code: 'auth.suspended');
        }

        if ($settings->get('auth.email_verification_required') && ! $user->hasVerifiedEmail()) {
            return ApiResponse::error(__('api.auth.unverified'), 403, code: 'auth.email_unverified');
        }

        RateLimiter::clear($key);
        $user->forceFill(['last_login_at' => now()])->save();
        $expiresAt = now()->addDays((int) $settings->get('auth.token_expiration_days'));
        $token = $user->createToken($credentials['device_name'], ['*'], $expiresAt);
        Audit::record('auth.login', $user);

        return ApiResponse::success(['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expiresAt->toISOString(), 'user' => $this->userData($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($this->userData($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'locale' => ['sometimes', 'string', Rule::in(config('localization.supported'))]]);
        $request->user()->update($data);
        Audit::record('user.profile_updated', $request->user());

        return ApiResponse::success($this->userData($request->user()->fresh()));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->getKey();
        $user->update(['password' => $data['password']]);
        $user->tokens()->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))->delete();
        Audit::record('auth.password_changed', $user);

        return ApiResponse::success(['message' => __('api.auth.password_changed')]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        Audit::record('auth.logout', $request->user());

        return ApiResponse::success(['message' => __('api.auth.logged_out')]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();
        Audit::record('auth.logout_all', $request->user());

        return ApiResponse::success(['message' => __('api.auth.tokens_revoked')]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->first();
        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return ApiResponse::success(['message' => __('api.auth.verification_sent')]);
    }

    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403, __('api.auth.invalid_verification'));
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            Audit::record('auth.email_verified', $user);
        }

        return ApiResponse::success(['message' => __('api.auth.verified')]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => Str::lower($data['email'])]);

        return ApiResponse::success(['message' => __('api.auth.reset_sent')]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
            Audit::record('auth.password_reset', $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(__($status), 422, ['email' => [__($status)]]);
        }

        return ApiResponse::success(['message' => __($status)]);
    }

    private function userData(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'locale' => $user->locale, 'status' => $user->status->value, 'email_verified_at' => $user->email_verified_at?->toISOString(), 'last_login_at' => $user->last_login_at?->toISOString(), 'roles' => $user->getRoleNames()->values(), 'permissions' => $user->getAllPermissions()->pluck('name')->values()];
    }
}
