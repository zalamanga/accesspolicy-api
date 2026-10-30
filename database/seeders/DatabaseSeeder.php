<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(810201)');
            if (DB::table('seed_markers')->where('name', 'demo-v1')->exists()) {
                return;
            }
            $names = ['owner' => 'Demo Owner', 'admin' => 'Demo Admin', 'member' => 'Demo Member', 'second-owner' => 'Demo Second Owner'];
            $users = [];
            foreach ($names as $key => $name) {
                $email = "$key@accesspolicy.test";
                // Email keys contain dots, so use array lookup rather than Laravel's dot notation.
                $password = config('demo.passwords')[$email] ?? null;
                if (!is_string($password) || strlen($password) < 24 || strlen($password) > 72) {
                    throw new \RuntimeException('Generate the four private demo passwords with scripts/Init.ps1 first.');
                }
                $users[$key] = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make($password)]);
            }
            foreach (['atlas' => 'Atlas Demo', 'birch' => 'Birch Demo'] as $slug => $name) {
                $org = DB::table('organizations')->where('slug', $slug)->first();
                $id = $org?->id ?? DB::table('organizations')->insertGetId(['slug' => $slug, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
                $roles = $slug === 'atlas'
                    ? ['owner' => 'owner', 'admin' => 'admin', 'member' => 'member', 'second-owner' => 'owner']
                    : ['second-owner' => 'owner', 'member' => 'viewer'];
                foreach ($roles as $key => $role) {
                    DB::table('memberships')->insertOrIgnore([
                        'organization_id' => $id, 'user_id' => $users[$key]->id, 'role' => $role, 'version' => 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('membership_versions')->insertOrIgnore(['organization_id' => $id, 'user_id' => $users[$key]->id, 'last_version' => 1]);
                }
            }
            DB::table('seed_markers')->insert(['name' => 'demo-v1', 'created_at' => now()]);
        });
    }
}
