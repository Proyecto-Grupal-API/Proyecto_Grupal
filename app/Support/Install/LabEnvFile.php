<?php

namespace App\Support\Install;

use InvalidArgumentException;

/**
 * Archivo de entorno de laboratorio (KEY=VALUE) que escribe `campus:install --demo`.
 * Regla central: un valor ya inicializado no se reemplaza salvo petición explícita (`put`).
 * Los valores no llevan comillas ni espacios, para que sirvan igual a Dotenv y a `--env-file` de contenedores.
 */
final class LabEnvFile
{
    /** @var list<string> */
    private array $lines;

    private bool $changed = false;

    /**
     * @param  bool  $dotenv  true: formato Dotenv (los valores JSON van entre comillas simples);
     *                        false: formato `--env-file` de contenedores (valor literal hasta el fin de línea).
     */
    public function __construct(private readonly string $path, private readonly bool $dotenv = true)
    {
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $this->lines = $content === '' ? [] : explode("\n", rtrim($content, "\n"));
    }

    public function get(string $key): ?string
    {
        $index = $this->index($key);
        $value = $index === null ? '' : trim(substr($this->lines[$index], strlen($key) + 1));
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }

        return $value === '' ? null : $value;
    }

    /** Escribe sólo si la clave falta o está vacía. Devuelve true si escribió. */
    public function putIfMissing(string $key, string $value): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }
        $this->put($key, $value);

        return true;
    }

    public function put(string $key, string $value): void
    {
        if (! preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) || preg_match('/[\s#"\']/', $value)) {
            throw new InvalidArgumentException("Clave o valor no válido para el archivo de entorno: {$key}");
        }
        if ($this->get($key) === $value) {
            return;
        }
        $index = $this->index($key);
        $index === null ? $this->lines[] = "{$key}={$value}" : $this->lines[$index] = "{$key}={$value}";
        $this->changed = true;
    }

    /** Valor JSON (p. ej. listas de clientes): se escribe sólo si falta, salvo `$replace`. Devuelve true si escribió. */
    public function putJson(string $key, array $value, bool $replace = false): bool
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) || preg_match('/[\r\n\']/', $json)) {
            throw new InvalidArgumentException("Clave o valor JSON no válido para el archivo de entorno: {$key}");
        }
        $current = $this->get($key);
        if ($current === $json || ($current !== null && ! $replace)) {
            return false;
        }
        $line = $key.'='.($this->dotenv ? "'".$json."'" : $json);
        $index = $this->index($key);
        $index === null ? $this->lines[] = $line : $this->lines[$index] = $line;
        $this->changed = true;

        return true;
    }

    public function changed(): bool
    {
        return $this->changed;
    }

    public function save(): void
    {
        if ($this->changed || ! is_file($this->path)) {
            file_put_contents($this->path, $this->lines === [] ? '' : implode("\n", $this->lines)."\n", LOCK_EX);
        }
        @chmod($this->path, 0600);
        $this->changed = false;
    }

    private function index(string $key): ?int
    {
        foreach ($this->lines as $i => $line) {
            if (str_starts_with($line, $key.'=')) {
                return $i;
            }
        }

        return null;
    }
}
