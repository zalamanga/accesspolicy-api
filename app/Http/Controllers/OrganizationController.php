<?php
namespace App\Http\Controllers;

use App\Exceptions\ApiProblem;
use App\Services\Input;
use App\Services\MembershipService;
use App\Services\Policy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class OrganizationController
{
    public function index(Request $request)
    {
        Input::query($request, []);
        return Policy::read($request, function () use ($request) {
            $rows = DB::table('organizations as o')->join('memberships as m', 'm.organization_id', '=', 'o.id')
                ->where('m.user_id', $request->user()->id)->orderBy('o.id')->get(['o.id', 'o.slug', 'o.name', 'm.role']);
            return response()->json(['data' => $rows]);
        });
    }

    public function members(Request $request, int $org)
    {
        [$after, $limit] = Input::page($request);
        return Policy::read($request, function () use ($request, $org, $after, $limit) {
            Policy::membership($org, $request->user()->id);
            $rows = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.organization_id', $org)->where('m.id', '>', $after)->orderBy('m.id')
                ->limit($limit + 1)->get(['m.id', 'm.user_id as userId', 'u.name as displayName', 'm.role', 'm.version']);
            $more = $rows->count() > $limit;
            $page = $rows->take($limit)->values();
            return response()->json(['data' => $page, 'nextAfterId' => $more ? $page->last()->id : null]);
        });
    }

    public function permissions(Request $request, int $org, int $user)
    {
        Input::query($request, []);
        return Policy::read($request, function () use ($request, $org, $user) {
            $actor = Policy::membership($org, $request->user()->id);
            if ((int) $request->user()->id !== $user && !in_array($actor->role, ['owner', 'admin'], true)) {
                throw new ApiProblem(403, 'forbidden', 'Your role can only read its own effective permissions.');
            }
            $target = Policy::membership($org, $user);
            return response()->json(['data' => ['organizationId' => $org, 'userId' => $user, 'role' => $target->role, 'version' => $target->version, 'permissions' => Policy::permissions($target->role)]]);
        });
    }

    public function create(Request $request, int $org, MembershipService $service)
    {
        $data = Input::body($request, ['userId' => ['required', 'integer', 'min:1', 'max:2147483647'], 'role' => ['required', Rule::in(Policy::ROLES)]], ['userId']);
        return response()->json(['data' => $service->mutate($request, $org, $data['userId'], $data['role'], null, true)], 201);
    }

    public function update(Request $request, int $org, int $user, MembershipService $service)
    {
        $data = Input::body($request, ['role' => ['required', Rule::in(Policy::ROLES)], 'expectedVersion' => ['required', 'integer', 'min:1', 'max:2147483646']], ['expectedVersion']);
        return response()->json(['data' => $service->mutate($request, $org, $user, $data['role'], $data['expectedVersion'])]);
    }

    public function remove(Request $request, int $org, int $user, MembershipService $service)
    {
        $data = Input::body($request, ['expectedVersion' => ['required', 'integer', 'min:1', 'max:2147483646']], ['expectedVersion']);
        return response()->json(['data' => $service->mutate($request, $org, $user, null, $data['expectedVersion'])]);
    }

    public function audit(Request $request, int $org)
    {
        [$after, $limit] = Input::page($request);
        return Policy::read($request, function () use ($request, $org, $after, $limit) {
            $actor = Policy::membership($org, $request->user()->id);
            if (!in_array($actor->role, ['owner', 'admin'], true)) {
                throw new ApiProblem(403, 'forbidden', 'Your role cannot read organization audit events.');
            }
            $rows = DB::table('audit_events')->where('organization_id', $org)->where('id', '>', $after)->orderBy('id')->limit($limit + 1)
                ->get(['id', 'actor_user_id as actorUserId', 'target_user_id as targetUserId', 'action', 'metadata', 'created_at as createdAt']);
            $more = $rows->count() > $limit;
            $page = $rows->take($limit)->map(function ($row) { $row->metadata = json_decode($row->metadata, true, 16, JSON_THROW_ON_ERROR); return $row; })->values();
            return response()->json(['data' => $page, 'nextAfterId' => $more ? $page->last()->id : null]);
        });
    }
}
