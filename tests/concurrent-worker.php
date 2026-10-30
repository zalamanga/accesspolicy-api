<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$input['applicationName']]);
if ($input['hold']) {
    $app->instance(App\Services\MutationCheckpoint::class, new class($input['barrier']) extends App\Services\MutationCheckpoint {
        public function __construct(private string $barrier) {}
        public function afterTokenRevocation(): void
        {
            file_put_contents($this->barrier.'/locked', 'ready');
            $deadline = microtime(true) + 25;
            while (!is_file($this->barrier.'/release')) {
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Race checkpoint timed out'); }
                clearstatcache(true, $this->barrier.'/release');
                usleep(10000);
            }
        }
    });
}
$request = Illuminate\Http\Request::create('/api/v1/orgs/'.$input['org'].'/members/'.$input['user'], 'PATCH', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
], json_encode(['role' => 'member', 'expectedVersion' => 1], JSON_THROW_ON_ERROR));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
