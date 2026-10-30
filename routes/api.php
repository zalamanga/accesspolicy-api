<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrganizationController;
use App\Services\Input;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (Request $request) {
    Input::query($request, []);
    DB::select('SELECT 1');
    return response()->json(['status' => 'ok']);
});
Route::get('/openapi.json', function (Request $request) {
    Input::query($request, []);
    return response()->file(base_path('docs/openapi.json'), ['Content-Type' => 'application/json']);
});
Route::post('/api/v1/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->prefix('/api/v1')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/orgs', [OrganizationController::class, 'index']);
    Route::get('/orgs/{org}/members', [OrganizationController::class, 'members'])->where('org', '[1-9][0-9]{0,9}');
    Route::post('/orgs/{org}/members', [OrganizationController::class, 'create'])->where('org', '[1-9][0-9]{0,9}');
    Route::patch('/orgs/{org}/members/{user}', [OrganizationController::class, 'update'])->where(['org' => '[1-9][0-9]{0,9}', 'user' => '[1-9][0-9]{0,9}']);
    Route::delete('/orgs/{org}/members/{user}', [OrganizationController::class, 'remove'])->where(['org' => '[1-9][0-9]{0,9}', 'user' => '[1-9][0-9]{0,9}']);
    Route::get('/orgs/{org}/members/{user}/permissions', [OrganizationController::class, 'permissions'])->where(['org' => '[1-9][0-9]{0,9}', 'user' => '[1-9][0-9]{0,9}']);
    Route::get('/orgs/{org}/audit', [OrganizationController::class, 'audit'])->where('org', '[1-9][0-9]{0,9}');
});
