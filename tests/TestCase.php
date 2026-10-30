<?php
namespace Tests;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as FrameworkTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends FrameworkTestCase
{
    protected array $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMockingConsoleOutput();
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'accesspolicy_test') {
            throw new \RuntimeException('Tests require the isolated PostgreSQL database accesspolicy_test. SQLite is not accepted.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(DatabaseSeeder::class);
        $this->users = User::all()->keyBy('email')->all();
    }

    protected function user(string $name): User
    {
        return $this->users["$name@accesspolicy.test"];
    }

    protected function org(string $slug = 'atlas'): int
    {
        return (int) DB::table('organizations')->where('slug', $slug)->value('id');
    }

    protected function token(string $name, array $abilities = ['api']): string
    {
        return $this->user($name)->createToken('test', $abilities, now()->addHour())->plainTextToken;
    }

    protected function version(string $name, string $slug = 'atlas'): int
    {
        return (int) DB::table('memberships')->where('organization_id', $this->org($slug))->where('user_id', $this->user($name)->id)->value('version');
    }

    protected function resetGuard(): void
    {
        // HTTP tests share the application, while real requests get a fresh auth guard.
        $this->app['auth']->forgetGuards();
    }

    protected function api(string $method, string $path, array $body = [], ?string $token = null)
    {
        $this->resetGuard();
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token) { $server['HTTP_AUTHORIZATION'] = "Bearer $token"; }
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return $this->call($method, $path, [], [], [], $server);
        }
        $server['CONTENT_TYPE'] = 'application/json';
        return $this->call($method, $path, [], [], [], $server, json_encode((object) $body, JSON_THROW_ON_ERROR));
    }
}
