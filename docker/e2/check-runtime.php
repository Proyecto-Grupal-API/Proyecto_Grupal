<?php
// Load the mounted configuration without bootstrapping Laravel or contacting DBs.
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
try {
    if (!is_file('/var/www/html/.env')) {
        throw new RuntimeException('Falta docker/e2/local/.env.');
    }
    Dotenv\Dotenv::createImmutable('/var/www/html')->load();
    $value = static function (string $name): string {
        $process = getenv($name);
        return $process === false ? (string) ($_ENV[$name] ?? '') : $process;
    };
    $key = $value('APP_KEY');
    if (!str_starts_with($key, 'base64:') || strlen((string) base64_decode(substr($key, 7), true)) !== 32) {
        throw new RuntimeException('Configura APP_KEY base64 valida de la instalacion; no se genera ni se imprime.');
    }
    foreach (['DB_DATABASE', 'MONGODB_DSN', 'SQLSRV_HOST', 'SQLSRV_DATABASE', 'SQLSRV_USERNAME', 'SQLSRV_PASSWORD'] as $name) {
        if (trim($value($name)) === '') {
            throw new RuntimeException('Falta configurar ' . $name . ' en docker/e2/local/.env.');
        }
    }
    if ($value('DB_CONNECTION') !== 'mongodb') {
        throw new RuntimeException('Este perfil requiere DB_CONNECTION=mongodb.');
    }
    foreach (['DB_DATABASE', 'SQLSRV_DATABASE'] as $name) {
        if (str_contains(strtolower($value($name)), 'testing')) {
            throw new RuntimeException('No uses las bases de testing en el servidor web local.');
        }
    }
    foreach (['DB_URL', 'SQLSRV_URL', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE'] as $name) {
        if ($value($name) !== '') {
            throw new RuntimeException('Retira ' . $name . ' para evitar configuracion alternativa en este perfil.');
        }
    }
    echo "Configuracion minima presente. No se comprobaron conexiones ni permisos.\n";
} catch (Throwable $error) {
    // Do not print exception details: dotenv errors may contain secret values.
    if ($error instanceof RuntimeException && !($error instanceof Dotenv\Exception\ExceptionInterface)) {
        fwrite(STDERR, $error->getMessage() . "\n");
    } else {
        fwrite(STDERR, "No se pudo leer la configuracion local. Revisa formato y permisos sin compartir secretos.\n");
    }
    exit(1);
}
