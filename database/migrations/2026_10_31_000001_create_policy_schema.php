<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name', 80); $table->string('email', 120)->unique();
            $table->string('password'); $table->timestampsTz();
        });
        Schema::create('organizations', function (Blueprint $table) {
            $table->id(); $table->string('slug', 50)->unique(); $table->string('name', 80); $table->timestampsTz();
        });
        Schema::create('memberships', function (Blueprint $table) {
            $table->id(); $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); $table->string('role', 10);
            $table->unsignedInteger('version')->default(1); $table->timestampsTz();
            $table->unique(['organization_id', 'user_id']); $table->index(['organization_id', 'id']);
        });
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT membership_role_valid CHECK (role IN ('owner','admin','member','viewer'))");
        DB::statement('ALTER TABLE memberships ADD CONSTRAINT membership_version_valid CHECK (version > 0)');
        Schema::create('membership_versions', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('last_version'); $table->primary(['organization_id', 'user_id']);
        });
        DB::statement('ALTER TABLE membership_versions ADD CONSTRAINT membership_counter_valid CHECK (last_version > 0)');
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id(); $table->morphs('tokenable'); $table->string('name', 80); $table->string('token', 64)->unique();
            $table->text('abilities')->nullable(); $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index(); $table->timestampsTz();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40); $table->jsonb('metadata'); $table->timestampTz('created_at');
            $table->index(['organization_id', 'id']); $table->index(['actor_user_id', 'id']);
        });
        Schema::create('login_window', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); $table->timestampTz('started_at'); $table->unsignedInteger('attempts');
        });
        DB::table('login_window')->insert(['id' => 1, 'started_at' => now()->subMinutes(6), 'attempts' => 0]);
        Schema::create('seed_markers', function (Blueprint $table) { $table->string('name')->primary(); $table->timestampTz('created_at'); });
        Schema::create('cache', function (Blueprint $table) { $table->string('key')->primary(); $table->mediumText('value'); $table->integer('expiration'); });
        Schema::create('cache_locks', function (Blueprint $table) { $table->string('key')->primary(); $table->string('owner'); $table->integer('expiration'); });
    }

    public function down(): void
    {
        foreach (['cache_locks', 'cache', 'seed_markers', 'login_window', 'audit_events', 'personal_access_tokens', 'membership_versions', 'memberships', 'organizations', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
