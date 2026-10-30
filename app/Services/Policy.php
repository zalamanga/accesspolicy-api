<?php
namespace App\Services;

use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class Policy
{
    public const ROLES = ['owner', 'admin', 'member', 'viewer'];

    public static function permissions(string $role): array
    {
        $permissions = ['members.read', 'permissions.self.read'];
        if (in_array($role, ['owner', 'admin'], true)) {
            array_push($permissions, 'audit.read', 'permissions.others.read', 'members.ordinary.manage');
        }
        if ($role === 'owner') {
            array_push($permissions, 'members.privileged.manage', 'membership.self.leave');
        }
        return $permissions;
    }

    public static function membership(int $organization, int $user): object
    {
        $member = DB::table('memberships')->where('organization_id', $organization)->where('user_id', $user)->first();
        if (!$member) {
            throw new ApiProblem(404, 'not_found', 'Resource not found.');
        }
        return $member;
    }

    public static function authorizeTarget(string $actorRole, int $actor, int $target, ?string $oldRole, ?string $newRole): void
    {
        if ($actorRole === 'owner') {
            return;
        }
        if ($actorRole !== 'admin' || $actor === $target || in_array($oldRole, ['owner', 'admin'], true) || in_array($newRole, ['owner', 'admin'], true)) {
            throw new ApiProblem(403, 'forbidden', 'Your role cannot perform this membership change.');
        }
    }

    public static function liveToken(Request $request): void
    {
        $token = $request->user()->currentAccessToken();
        if (!$token instanceof PersonalAccessToken || !PersonalAccessToken::whereKey($token->id)
            ->where('tokenable_id', $request->user()->id)->where('tokenable_type', User::class)
            ->where('expires_at', '>', now())->exists()) {
            throw new ApiProblem(401, 'unauthenticated', 'A valid bearer token is required.');
        }
    }

    public static function read(Request $request, callable $callback): mixed
    {
        return DB::transaction(function () use ($request, $callback) {
            // Membership writers lock this same user row before revoking tokens or changing rights.
            User::whereKey($request->user()->id)->sharedLock()->firstOrFail();
            self::liveToken($request);
            return $callback();
        });
    }
}
