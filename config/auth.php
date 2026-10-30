<?php
return [
    'defaults' => ['guard' => 'sanctum', 'passwords' => 'users'],
    'guards' => ['sanctum' => ['driver' => 'sanctum', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => App\Models\User::class]],
];
