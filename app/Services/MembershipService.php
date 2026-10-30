<?php
namespace App\Services;

use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MembershipService
{
    public function __construct(private MutationCheckpoint $checkpoint) {}

    public function mutate(Request $request, int $orgId, int $targetId, ?string $newRole, ?int $expectedVersion, bool $create = false): array
    {
        return DB::transaction(function () use ($request, $orgId, $targetId, $newRole, $expectedVersion, $create): array {
            $organization = DB::table('organizations')->where('id', $orgId)->lockForUpdate()->first();
            if (!$organization) {
                throw new ApiProblem(404, 'not_found', 'Resource not found.');
            }
            $actorId = (int) $request->user()->id;
            $ids = array_values(array_unique([$actorId, $targetId]));
            sort($ids, SORT_NUMERIC);
            // Global user order also serializes revocation against login and cross-organization edits.
            $users = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            Policy::liveToken($request);
            $actor = Policy::membership($orgId, $actorId);
            $target = DB::table('memberships')->where('organization_id', $orgId)->where('user_id', $targetId)->first();
            Policy::authorizeTarget($actor->role, $actorId, $targetId, $target?->role, $newRole);
            if ($create) {
                if (!$users->has($targetId)) {
                    throw new ApiProblem(404, 'not_found', 'Resource not found.');
                }
                if ($target) {
                    throw new ApiProblem(409, 'membership_exists', 'This user is already a member.');
                }
            } elseif (!$target) {
                throw new ApiProblem(404, 'not_found', 'Resource not found.');
            } elseif ((int) $target->version !== $expectedVersion) {
                throw new ApiProblem(409, 'stale_version', 'Membership changed. Read its current version before retrying.');
            } elseif ($target->role === $newRole) {
                throw new ApiProblem(409, 'no_change', 'The membership already has this role.');
            }
            if ($target?->role === 'owner' && $newRole !== 'owner') {
                $owners = DB::table('memberships')->where('organization_id', $orgId)->where('role', 'owner')->count();
                if ($owners <= 1) {
                    throw new ApiProblem(409, 'last_owner', 'The last owner cannot leave or be demoted.');
                }
            }
            // A deleted/re-added membership must never accept a version from its previous lifetime.
            $lastVersion = (int) DB::table('membership_versions')->where('organization_id', $orgId)->where('user_id', $targetId)->value('last_version');
            $previousVersion = max($lastVersion, (int) ($target?->version ?? 0));
            if ($previousVersion >= 2147483647) {
                throw new ApiProblem(409, 'version_exhausted', 'The membership version limit has been reached.');
            }
            $version = $previousVersion + 1;
            DB::table('membership_versions')->updateOrInsert(
                ['organization_id' => $orgId, 'user_id' => $targetId], ['last_version' => $version],
            );
            if ($create) {
                $id = DB::table('memberships')->insertGetId([
                    'organization_id' => $orgId, 'user_id' => $targetId, 'role' => $newRole, 'version' => $version,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $id = (int) $target->id;
                if ($newRole === null) {
                    DB::table('memberships')->where('id', $id)->delete();
                } else {
                    DB::table('memberships')->where('id', $id)->update(['role' => $newRole, 'version' => $version, 'updated_at' => now()]);
                }
            }
            $revoked = $users[$targetId]->tokens()->delete();
            $this->checkpoint->afterTokenRevocation();
            $action = $create ? 'membership.created' : ($newRole === null ? 'membership.removed' : 'membership.role_changed');
            Audit::record($orgId, $actorId, $targetId, $action, [
                'oldRole' => $target?->role, 'newRole' => $newRole, 'version' => $version, 'tokensRevoked' => $revoked,
            ]);
            return ['id' => $id, 'userId' => $targetId, 'role' => $newRole, 'version' => $version, 'tokensRevoked' => $revoked];
        }, 3);
    }
}
