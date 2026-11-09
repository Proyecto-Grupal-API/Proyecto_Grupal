<?php
try {
    require __DIR__.'/bootstrap.php';
    $job = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    becaAssert(is_array($job) && preg_match('/\A[a-f0-9]{32}\z/', $job['token'] ?? '') === 1, 'Trabajo no valido.');
    $dir = $job['directory'];
    becaAssert(is_dir($dir) && hash_equals(trim(file_get_contents($dir.'/owner')), $job['token']), 'Barrera sin propietario.');
    becaTestBindings($job['fixture']['operator'], $job['fixture']['buyer']);
    $spid = (int) $sql->selectOne('SELECT @@SPID AS spid')->spid;
    // This callback runs immediately BEFORE submitting the actual budget-lock SQL query.
    $sql->beforeExecuting(function ($query) use ($dir) {
        if (str_contains(strtolower($query), 'institutional_bonus_budgets')
            && str_contains(strtolower($query), 'updlock')) {
            becaSignal($dir.'/locking', '1');
        }
    });
    becaSignal($dir.'/ready', json_encode(['spid' => $spid, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
    becaWait(fn () => is_file($dir.'/go'), 'No se libero la barrera de inicio.');
    $start = microtime(true);
    try {
        $value = becaOperation($job);
        $result = ['status' => 'OK', 'value' => $value];
    } catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
        $result = ['status' => 'CONFLICT', 'http_status' => 409];
    } catch (Throwable $e) {
        // SQL deadlock (1205), timeout (1222) and infrastructure errors are FAILURES, never accepted conflicts.
        $result = ['status' => 'ERROR', 'exception' => get_class($e),
            'sql_code' => $e instanceof Illuminate\Database\QueryException ? ($e->errorInfo[1] ?? null) : null];
    }
    Illuminate\Support\Facades\Http::assertNothingSent();
    $result += ['spid' => $spid, 'pid' => getmypid(), 'elapsed_ms' => round((microtime(true) - $start) * 1000)];
    becaSignal($dir.'/result', json_encode($result, JSON_THROW_ON_ERROR));
    exit($result['status'] === 'ERROR' ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(2);
}
