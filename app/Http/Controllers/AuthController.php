<?php
namespace App\Http\Controllers;

use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Services\Audit;
use App\Services\Input;
use App\Services\Policy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AuthController
{
    public function login(Request $request)
    {
        $data = Input::body($request, ['email' => ['required', 'string', 'email', 'max:120'], 'password' => ['required', 'string', 'min:1', 'max:72']]);
        if (strlen($data['password']) > 72) {
            throw new ApiProblem(422, 'validation_failed', 'Password must contain at most 72 UTF-8 bytes.');
        }
        $wait = DB::transaction(function (): int {
            $window = DB::table('login_window')->where('id', 1)->lockForUpdate()->first();
            $start = \Illuminate\Support\Carbon::parse($window->started_at);
            if ($start->addSeconds(300)->isPast()) {
                DB::table('login_window')->where('id', 1)->update(['attempts' => 1, 'started_at' => now()]);
                return 0;
            }
            if ((int) $window->attempts >= 10) {
                return max(1, (int) ceil(now()->diffInSeconds($start, false)));
            }
            DB::table('login_window')->where('id', 1)->increment('attempts');
            return 0;
        });
        if ($wait > 0) {
            return response()->json(['error' => ['code' => 'login_throttled', 'message' => 'Login limit reached. Try again later.', 'details' => null]], 429)->header('Retry-After', (string) $wait);
        }
        $user = User::where('email', strtolower($data['email']))->first();
        // Same bcrypt work for unknown accounts; this fixed value is a hash, never a usable credential.
        $hash = $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = Hash::check($data['password'], $hash);
        if (!$user || !$valid) {
            throw new ApiProblem(401, 'invalid_credentials', 'Email or password is incorrect.');
        }
        return DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $pruned = $locked->tokens()->where('expires_at', '<=', now())->delete();
            if ($pruned > 0) {
                Audit::record(null, $locked->id, $locked->id, 'token.expired_pruned', ['tokensRevoked' => $pruned]);
            }
            if ($locked->tokens()->count() >= 20) {
                throw new ApiProblem(429, 'token_limit', 'Revoke an existing token before creating another.');
            }
            $expires = now()->addHour();
            $token = $locked->createToken('api-client', ['api'], $expires);
            Audit::record(null, $locked->id, $locked->id, 'token.issued', ['expiresAt' => $expires->toIso8601String()]);
            return response()->json(['data' => ['token' => $token->plainTextToken, 'tokenType' => 'Bearer', 'expiresAt' => $expires->toIso8601String()]], 201);
        });
    }

    public function logout(Request $request)
    {
        Input::body($request, []);
        DB::transaction(function () use ($request): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            Policy::liveToken($request);
            $request->user()->currentAccessToken()->delete();
            Audit::record(null, $request->user()->id, $request->user()->id, 'token.revoked', ['tokensRevoked' => 1]);
        });
        return response()->noContent();
    }

    public function me(Request $request)
    {
        Input::query($request, []);
        return Policy::read($request, fn () => response()->json(['data' => [
            'id' => $request->user()->id, 'name' => $request->user()->name, 'email' => $request->user()->email,
        ]]));
    }
}
