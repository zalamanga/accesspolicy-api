<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(?int $organization, int $actor, int $target, string $action, array $metadata): void
    {
        DB::table('audit_events')->insert([
            'organization_id' => $organization, 'actor_user_id' => $actor, 'target_user_id' => $target,
            'action' => $action, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
