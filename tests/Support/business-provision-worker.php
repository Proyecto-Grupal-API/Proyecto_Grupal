<?php

use App\Models\BusinessOwnerClaim;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Separate PHP process, real HTTP kernel and MongoDB transaction; never cleans or seeds a database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mongodb'
    || DB::connection('mongodb')->getDatabaseName() !== 'campus_virtual_testing') {
    throw new RuntimeException('Blocked non-testing worker.');
}
$data = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
BusinessOwnerClaim::creating(function () use ($data) {
    // Both real transactions reach their first claim insertion before either can commit.
    file_put_contents($data['barrier'].'/'.$data['index'], 'ready', LOCK_EX);
    $deadline = microtime(true) + 10;
    while (! file_exists($data['barrier'].'/'.(1 - $data['index']))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrent transaction barrier timed out.');
        }
        usleep(1000);
    }
});
$request = Request::create('/api/v1/identity/business-owner/provision', 'POST', [], [], [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => $data['headers']['Authorization']], json_encode($data['payload']));
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
