<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\MutationCheckpoint;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PolicyApiTest extends TestCase
{
    public function test_health_and_machine_contract_are_available(): void
    {
        $this->api('GET', '/health')->assertOk()->assertJsonPath('status', 'ok');
        $response = $this->api('GET', '/openapi.json')->assertOk();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame('3.0.3', json_decode(file_get_contents($response->baseResponse->getFile()->getRealPath()), true)['openapi']);
    }

    public function test_login_returns_token_once_and_logout_revokes_it(): void
    {
        $response = $this->api('POST', '/api/v1/auth/login', ['email' => 'owner@accesspolicy.test', 'password' => config('demo.passwords')['owner@accesspolicy.test']])
            ->assertCreated()->assertJsonPath('data.tokenType', 'Bearer');
        $token = $response->json('data.token');
        $this->api('GET', '/api/v1/me', [], $token)->assertOk()->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.token');
        $this->api('POST', '/api/v1/auth/logout', [], $token)->assertNoContent();
        $this->api('GET', '/api/v1/me', [], $token)->assertUnauthorized();
        $this->assertSame(['token.issued', 'token.revoked'], DB::table('audit_events')->orderBy('id')->pluck('action')->all());
    }

    public function test_expired_and_missing_tokens_are_rejected(): void
    {
        $token = $this->user('owner')->createToken('expired', ['*'], now()->subSecond())->plainTextToken;
        $this->api('GET', '/api/v1/me')->assertUnauthorized();
        $this->api('GET', '/api/v1/me', [], $token)->assertUnauthorized();
    }

    public function test_organization_listing_is_scoped_and_other_tenant_is_404(): void
    {
        $token = $this->token('owner');
        $this->api('GET', '/api/v1/orgs', [], $token)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'atlas');
        $this->api('GET', '/api/v1/orgs/'.$this->org('birch').'/members', [], $token)->assertNotFound();
        $this->api('GET', '/api/v1/orgs/'.$this->org('birch').'/audit', [], $token)->assertNotFound();
        $this->api('PATCH', '/api/v1/orgs/'.$this->org('birch').'/members/'.$this->user('member')->id, ['role' => 'admin', 'expectedVersion' => 1], $token)->assertNotFound();
    }

    public function test_star_token_abilities_cannot_bypass_role_permissions(): void
    {
        $token = $this->token('member', ['*']);
        $org = $this->org();
        $this->api('GET', "/api/v1/orgs/$org/members", [], $token)->assertOk()->assertJsonMissingPath('data.0.email');
        $this->api('PATCH', "/api/v1/orgs/$org/members/".$this->user('member')->id, ['role' => 'owner', 'expectedVersion' => 1], $token)->assertForbidden();
        $this->api('GET', "/api/v1/orgs/$org/audit", [], $token)->assertForbidden();
    }

    public function test_admin_cannot_assign_privileged_roles_or_modify_owner_or_self(): void
    {
        $token = $this->token('admin'); $org = $this->org();
        foreach (['owner', 'admin', 'member'] as $name) {
            $this->api('PATCH', "/api/v1/orgs/$org/members/".$this->user($name)->id, ['role' => 'admin', 'expectedVersion' => 1], $token)->assertForbidden();
        }
        $this->api('DELETE', "/api/v1/orgs/$org/members/".$this->user('owner')->id, ['expectedVersion' => 1], $token)->assertForbidden();
    }

    public function test_admin_can_change_ordinary_member_and_revokes_all_cross_org_tokens(): void
    {
        $member = $this->token('member'); $another = $this->token('member');
        $org = $this->org();
        $this->api('PATCH', "/api/v1/orgs/$org/members/".$this->user('member')->id, ['role' => 'viewer', 'expectedVersion' => 1], $this->token('admin'))
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.tokensRevoked', 2);
        $this->api('GET', '/api/v1/me', [], $member)->assertUnauthorized();
        $this->api('GET', '/api/v1/orgs/'.$this->org('birch').'/members', [], $another)->assertUnauthorized();
        $this->assertSame(0, $this->user('member')->tokens()->count());
    }

    public function test_membership_removal_revokes_token_and_new_login_sees_no_membership(): void
    {
        $member = $this->token('member'); $org = $this->org();
        $this->api('DELETE', "/api/v1/orgs/$org/members/".$this->user('member')->id, ['expectedVersion' => 1], $this->token('admin'))->assertOk();
        $this->api('GET', '/api/v1/me', [], $member)->assertUnauthorized();
        $fresh = $this->api('POST', '/api/v1/auth/login', ['email' => 'member@accesspolicy.test', 'password' => config('demo.passwords')['member@accesspolicy.test']])->assertCreated()->json('data.token');
        $this->api('GET', "/api/v1/orgs/$org/members", [], $fresh)->assertNotFound();
        $this->api('GET', '/api/v1/orgs', [], $fresh)->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'birch');
    }

    public function test_fresh_login_after_admin_demotion_reads_current_database_permissions(): void
    {
        $old = $this->token('admin'); $org = $this->org(); $adminId = $this->user('admin')->id;
        $this->api('PATCH', "/api/v1/orgs/$org/members/$adminId", ['role' => 'viewer', 'expectedVersion' => 1], $this->token('owner'))->assertOk();
        $this->api('GET', '/api/v1/me', [], $old)->assertUnauthorized();
        $fresh = $this->api('POST', '/api/v1/auth/login', ['email' => 'admin@accesspolicy.test', 'password' => config('demo.passwords')['admin@accesspolicy.test']])->assertCreated()->json('data.token');
        $this->api('GET', "/api/v1/orgs/$org/members/$adminId/permissions", [], $fresh)->assertOk()->assertJsonPath('data.role', 'viewer')->assertJsonPath('data.permissions', ['members.read', 'permissions.self.read']);
        $this->api('PATCH', "/api/v1/orgs/$org/members/".$this->user('member')->id, ['role' => 'viewer', 'expectedVersion' => 1], $fresh)->assertForbidden();
    }

    public function test_stale_expected_version_is_409_and_does_not_revoke_new_token(): void
    {
        $admin = $this->token('admin'); $org = $this->org(); $id = $this->user('member')->id;
        $this->api('PATCH', "/api/v1/orgs/$org/members/$id", ['role' => 'viewer', 'expectedVersion' => 1], $admin)->assertOk();
        $new = $this->token('member');
        $this->api('PATCH', "/api/v1/orgs/$org/members/$id", ['role' => 'member', 'expectedVersion' => 1], $admin)->assertConflict()->assertJsonPath('error.code', 'stale_version');
        $this->api('GET', '/api/v1/me', [], $new)->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('organization_id', $org)->count());
    }

    public function test_owner_can_add_existing_user_and_duplicate_add_is_409(): void
    {
        $org = $this->org('birch'); $token = $this->token('second-owner'); $id = $this->user('admin')->id;
        $this->api('POST', "/api/v1/orgs/$org/members", ['userId' => $id, 'role' => 'admin'], $token)->assertCreated()->assertJsonPath('data.version', 1);
        $this->api('POST', "/api/v1/orgs/$org/members", ['userId' => $id, 'role' => 'admin'], $token)->assertConflict();
    }

    public function test_last_owner_cannot_be_removed_or_demoted(): void
    {
        $org = $this->org('birch'); $id = $this->user('second-owner')->id; $token = $this->token('second-owner');
        $this->api('PATCH', "/api/v1/orgs/$org/members/$id", ['role' => 'member', 'expectedVersion' => 1], $token)->assertConflict()->assertJsonPath('error.code', 'last_owner');
        $this->api('DELETE', "/api/v1/orgs/$org/members/$id", ['expectedVersion' => 1], $token)->assertConflict()->assertJsonPath('error.code', 'last_owner');
    }

    public function test_remove_and_readd_never_accepts_version_from_previous_membership(): void
    {
        $org = $this->org(); $id = $this->user('member')->id; $token = $this->token('admin');
        $oldId = DB::table('memberships')->where('organization_id', $org)->where('user_id', $id)->value('id');
        $this->api('DELETE', "/api/v1/orgs/$org/members/$id", ['expectedVersion' => 1], $token)->assertOk()->assertJsonPath('data.version', 2);
        $created = $this->api('POST', "/api/v1/orgs/$org/members", ['userId' => $id, 'role' => 'member'], $token)->assertCreated()->assertJsonPath('data.version', 3);
        $this->assertNotSame($oldId, $created->json('data.id'));
        $this->api('PATCH', "/api/v1/orgs/$org/members/$id", ['role' => 'viewer', 'expectedVersion' => 1], $token)->assertConflict()->assertJsonPath('error.code', 'stale_version');
        $this->assertSame(3, $this->version('member'));
    }

    public function test_owner_can_leave_when_another_owner_exists(): void
    {
        $org = $this->org(); $id = $this->user('owner')->id; $token = $this->token('owner');
        $this->api('DELETE', "/api/v1/orgs/$org/members/$id", ['expectedVersion' => 1], $token)->assertOk();
        $this->api('GET', '/api/v1/me', [], $token)->assertUnauthorized();
        $this->assertSame(1, DB::table('memberships')->where('organization_id', $org)->where('role', 'owner')->count());
    }

    public function test_failure_between_token_revocation_and_audit_rolls_back_everything(): void
    {
        $member = $this->token('member'); $admin = $this->token('admin'); $org = $this->org();
        $this->app->instance(MutationCheckpoint::class, new class extends MutationCheckpoint {
            public function afterTokenRevocation(): void { throw new \RuntimeException('Injected transactional failure'); }
        });
        $this->api('PATCH', "/api/v1/orgs/$org/members/".$this->user('member')->id, ['role' => 'viewer', 'expectedVersion' => 1], $admin)->assertStatus(500);
        $this->assertSame('member', DB::table('memberships')->where('user_id', $this->user('member')->id)->where('organization_id', $org)->value('role'));
        $this->assertSame(1, $this->version('member'));
        $this->assertSame(1, DB::table('membership_versions')->where('organization_id', $org)->where('user_id', $this->user('member')->id)->value('last_version'));
        $this->assertSame(0, DB::table('audit_events')->count());
        $this->api('GET', '/api/v1/me', [], $member)->assertOk();
    }

    public function test_two_concurrent_owner_self_demotions_leave_exactly_one_owner(): void
    {
        $org = $this->org(); $processes = [];
        $suffix = bin2hex(random_bytes(8));
        $barrier = sys_get_temp_dir().'/accesspolicy-race-'.$suffix;
        mkdir($barrier, 0700);
        try {
            foreach (['owner', 'second-owner'] as $index => $name) {
                $process = new Process([PHP_BINARY, base_path('tests/concurrent-worker.php')], base_path(), null, json_encode([
                    'token' => $this->token($name), 'org' => $org, 'user' => $this->user($name)->id,
                    'barrier' => $barrier, 'hold' => $index === 0, 'applicationName' => "accesspolicy-$suffix-$index",
                ], JSON_THROW_ON_ERROR), 30);
                $process->start(); $processes[] = $process;
                if ($index === 0) {
                    $deadline = microtime(true) + 10;
                    while (!is_file($barrier.'/locked') && $process->isRunning() && microtime(true) < $deadline) { clearstatcache(true, $barrier.'/locked'); usleep(10000); }
                    $this->assertFileExists($barrier.'/locked', 'First request did not hold the transaction at the checkpoint.');
                }
            }
            $blocked = false; $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && $processes[1]->isRunning()) {
                $blocked = DB::table('pg_stat_activity')->where('application_name', "accesspolicy-$suffix-1")->where('wait_event_type', 'Lock')->exists();
                if ($blocked) { break; }
                usleep(10000);
            }
            $this->assertTrue($blocked, 'Second request must be observed waiting on a real PostgreSQL lock before the first commits.');
            touch($barrier.'/release');
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'Concurrent worker did not complete: '.$process->getErrorOutput());
                $statuses[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertSame(1, DB::table('memberships')->where('organization_id', $org)->where('role', 'owner')->count());
            $this->assertSame(1, DB::table('audit_events')->where('organization_id', $org)->count());
        } finally {
            touch($barrier.'/release');
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            foreach (['locked', 'release'] as $file) { if (is_file($barrier.'/'.$file)) { unlink($barrier.'/'.$file); } }
            rmdir($barrier);
        }
    }

    public function test_seed_reexecution_preserves_passwords_and_membership_changes(): void
    {
        $user = $this->user('member'); $hash = Hash::make(str_repeat('z', 32));
        $user->forceFill(['password' => $hash])->save();
        DB::table('memberships')->where('organization_id', $this->org())->where('user_id', $user->id)->delete();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertFalse(DB::table('memberships')->where('organization_id', $this->org())->where('user_id', $user->id)->exists());
    }

    public function test_database_login_window_counts_valid_and_invalid_attempts_and_ignores_forwarded_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('X-Forwarded-For', '198.51.100.'.($i + 1));
            $body = $i === 0
                ? ['email' => 'owner@accesspolicy.test', 'password' => config('demo.passwords')['owner@accesspolicy.test']]
                : ['email' => 'unknown@accesspolicy.test', 'password' => 'wrong'];
            $this->api('POST', '/api/v1/auth/login', $body)->assertStatus($i === 0 ? 201 : 401);
        }
        $this->api('POST', '/api/v1/auth/login', ['email' => 'owner@accesspolicy.test', 'password' => config('demo.passwords')['owner@accesspolicy.test']])->assertStatus(429)->assertHeader('Retry-After');
        DB::table('login_window')->where('id', 1)->update(['started_at' => now()->subSeconds(301)]);
        $this->api('POST', '/api/v1/auth/login', ['email' => 'unknown@accesspolicy.test', 'password' => 'wrong'])->assertUnauthorized();
        $this->assertSame(1, DB::table('login_window')->value('attempts'));
    }

    public function test_permissions_visibility_and_audit_pagination_are_bounded(): void
    {
        $org = $this->org(); $memberId = $this->user('member')->id;
        $token = $this->token('member');
        $this->api('GET', "/api/v1/orgs/$org/members/$memberId/permissions", [], $token)->assertOk()->assertJsonPath('data.permissions', ['members.read', 'permissions.self.read']);
        $this->api('GET', "/api/v1/orgs/$org/members/".$this->user('owner')->id.'/permissions', [], $token)->assertForbidden();
        $owner = $this->token('owner');
        $this->api('GET', "/api/v1/orgs/$org/members?limit=2", [], $owner)->assertOk()->assertJsonCount(2, 'data');
        $this->api('GET', "/api/v1/orgs/$org/audit?limit=51", [], $owner)->assertUnprocessable();
        $this->api('GET', "/api/v1/orgs/$org/members?afterId=-1", [], $owner)->assertUnprocessable();
    }

    public function test_unknown_fields_and_string_versions_are_rejected(): void
    {
        $this->api('POST', '/api/v1/auth/login', ['email' => 'owner@accesspolicy.test', 'password' => 'wrong', 'admin' => true])->assertUnprocessable()->assertJsonPath('error.code', 'unknown_fields');
        $org = $this->org();
        $this->api('PATCH', "/api/v1/orgs/$org/members/".$this->user('member')->id, ['role' => 'viewer', 'expectedVersion' => '1'], $this->token('owner'))->assertUnprocessable();
        $this->api('GET', '/api/v1/me?unexpected=1', [], $this->token('owner'))->assertUnprocessable();
    }

    public function test_malformed_oversized_and_non_object_json_are_rejected(): void
    {
        $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{')->assertStatus(400);
        $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]')->assertStatus(400);
        $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"x":"'.str_repeat('a', 16384).'"}')->assertStatus(413);
        $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
    }
}
