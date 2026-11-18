<?php

namespace App\Services\Provisioning;

use App\Services\Audit\Redactor;

/**
 * Rencana langkah provisioning. Dibuat driver, ditampilkan di preview,
 * lalu dieksekusi ProvisioningService. Marker #WCD# / #I# diisi saat runtime
 * setelah AddObject membuat instance baru.
 */
final class ProvisioningPlan
{
    public const MARK_WCD = '#WCD#';

    public const MARK_I = '#I#';

    /** @var list<array<string, mixed>> */
    public array $steps = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, bool> key mapping => verified */
    public array $keys = [];

    /** @var array<string, mixed> ringkasan untuk preview */
    public array $summary = [];

    public function addObject(string $object, string $assign, string $description): void
    {
        $this->steps[] = ['type' => 'add_object', 'object' => $object, 'assign' => $assign, 'description' => $description];
    }

    /**
     * @param  list<array{path: string, value: mixed, type: string, key: string, secret?: bool}>  $values
     */
    public function setValues(array $values, string $description): void
    {
        if ($values === []) {
            return;
        }
        $this->steps[] = ['type' => 'set_values', 'values' => array_values($values), 'description' => $description];
    }

    public function value(string $path, mixed $value, string $type, string $key, bool $verified, bool $secret = false): array
    {
        $this->keys[$key] = $verified && ($this->keys[$key] ?? true);

        return ['path' => $path, 'value' => $value, 'type' => $type, 'key' => $key, 'secret' => $secret];
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @return list<string> */
    public function unverifiedKeys(): array
    {
        return array_keys(array_filter($this->keys, fn (bool $v) => ! $v));
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return list<array{path: string, value: mixed, type: string, key: string, secret: bool}> */
    public function allValues(): array
    {
        $out = [];
        foreach ($this->steps as $step) {
            if ($step['type'] === 'set_values') {
                array_push($out, ...$step['values']);
            }
        }

        return $out;
    }

    public function createsObjects(): bool
    {
        return collect($this->steps)->contains('type', 'add_object');
    }

    /** @return array<string, mixed> */
    public function toArray(bool $redact = true): array
    {
        $steps = $this->steps;
        if ($redact) {
            foreach ($steps as &$step) {
                if ($step['type'] === 'set_values') {
                    foreach ($step['values'] as &$v) {
                        if ($v['secret'] || Redactor::isSecretKey($v['path'])) {
                            $v['value'] = Redactor::MASK;
                        }
                    }
                }
            }
            unset($step, $v);
        }

        return [
            'steps' => $steps,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'unverified_keys' => $this->unverifiedKeys(),
            'summary' => $redact ? Redactor::redact($this->summary) : $this->summary,
        ];
    }
}
