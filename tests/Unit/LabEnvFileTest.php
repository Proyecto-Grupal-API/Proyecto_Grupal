<?php

use App\Support\Install\LabEnvFile;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/lab-env-'.bin2hex(random_bytes(6)).'.env';
});

afterEach(function () {
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

test('a missing file starts empty and is created private on save', function () {
    $env = new LabEnvFile($this->path);
    expect($env->get('DEMO_KEY'))->toBeNull();
    expect($env->putIfMissing('DEMO_KEY', 'value-1'))->toBeTrue();
    $env->save();

    expect(file_get_contents($this->path))->toBe("DEMO_KEY=value-1\n");
    expect(substr(sprintf('%o', fileperms($this->path)), -3))->toBe('600');
});

test('an initialized value is never replaced, an empty one is filled, and other lines are preserved', function () {
    file_put_contents($this->path, "# comentario\nAPP_NAME=Campus Digital\nCLIENT_SECRET=ya-inicializado\nEMPTY_KEY=\n");
    $env = new LabEnvFile($this->path);

    expect($env->putIfMissing('CLIENT_SECRET', 'otro'))->toBeFalse();
    expect($env->putIfMissing('EMPTY_KEY', 'relleno'))->toBeTrue();
    expect($env->putIfMissing('NEW_KEY', 'nuevo'))->toBeTrue();
    $env->save();

    expect(file_get_contents($this->path))
        ->toBe("# comentario\nAPP_NAME=Campus Digital\nCLIENT_SECRET=ya-inicializado\nEMPTY_KEY=relleno\nNEW_KEY=nuevo\n");
    expect((new LabEnvFile($this->path))->get('CLIENT_SECRET'))->toBe('ya-inicializado');
});

test('put replaces a value explicitly and a second identical run changes nothing', function () {
    file_put_contents($this->path, "CASH=old\n");
    $env = new LabEnvFile($this->path);
    $env->put('CASH', 'new');
    expect($env->changed())->toBeTrue();
    $env->save();

    $again = new LabEnvFile($this->path);
    $again->put('CASH', 'new');
    expect($again->putIfMissing('CASH', 'x'))->toBeFalse();
    expect($again->changed())->toBeFalse();
});

test('keys and values that could break the file are rejected', function (string $key, string $value) {
    (new LabEnvFile($this->path))->put($key, $value);
})->with([['lower_case', 'x'], ['GOOD_KEY', "two\nlines"], ['GOOD_KEY', 'with space'], ['GOOD_KEY', 'with#hash']])
    ->throws(InvalidArgumentException::class);

test('json values are written once, quoted for dotenv and literal for container env files', function () {
    $dotenv = new LabEnvFile($this->path);
    expect($dotenv->putJson('CLIENTS_JSON', ['svc_a' => ['name' => 'Tienda Demo', 'ids' => ['B-1' => 'uuid']]]))->toBeTrue();
    expect($dotenv->putJson('CLIENTS_JSON', ['otro' => []]))->toBeFalse(); // ya inicializado
    $dotenv->save();
    expect(file_get_contents($this->path))->toBe("CLIENTS_JSON='{\"svc_a\":{\"name\":\"Tienda Demo\",\"ids\":{\"B-1\":\"uuid\"}}}'\n");
    expect(json_decode((string) (new LabEnvFile($this->path))->get('CLIENTS_JSON'), true)['svc_a']['name'])->toBe('Tienda Demo');

    $container = new LabEnvFile($this->path, false);
    expect($container->putJson('CLIENTS_JSON', ['svc_b' => ['name' => 'Otra']], true))->toBeTrue(); // reemplazo explícito
    $container->save();
    expect(file_get_contents($this->path))->toBe("CLIENTS_JSON={\"svc_b\":{\"name\":\"Otra\"}}\n");
});
