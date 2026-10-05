<?php

declare(strict_types=1);

namespace Codeskop;

/**
 * Small cross-request cache for remote config and API Trust verdicts (PHP shares nothing between
 * requests). APCu when available, else a JSON file in the temp directory. Failures are silent.
 */
final class Cache
{
    private bool $apcu;
    private string $dir;

    public function __construct(private string $namespace, ?string $dir = null)
    {
        $this->apcu = function_exists('apcu_fetch') && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN));
        $this->dir = $dir ?? sys_get_temp_dir();
    }

    public function get(string $key): ?array
    {
        try {
            if ($this->apcu) {
                $v = apcu_fetch($this->key($key), $ok);
                return $ok && is_array($v) ? $v : null;
            }
            $raw = @file_get_contents($this->file($key));
            $v = $raw === false ? null : json_decode($raw, true);
            return is_array($v) ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(string $key, array $value): void
    {
        try {
            if ($this->apcu) {
                apcu_store($this->key($key), $value, 86400);
                return;
            }
            $file = $this->file($key);
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, json_encode($value)) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $file);
            }
        } catch (\Throwable) {
        }
    }

    private function key(string $key): string
    {
        return 'codeskop:' . $this->namespace . ':' . $key;
    }

    private function file(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'codeskop-' . $this->namespace . '-' . $key . '.json';
    }
}
