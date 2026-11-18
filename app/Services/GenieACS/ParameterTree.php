<?php

namespace App\Services\GenieACS;

use App\Services\Audit\Redactor;
use Carbon\CarbonImmutable;

/**
 * Pembungkus read-only untuk dokumen device GenieACS (struktur bersarang
 * dengan atribut _value, _type, _writable, _timestamp, _object).
 */
final class ParameterTree
{
    /** @param array<string, mixed> $document */
    public function __construct(private readonly array $document) {}

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document): self
    {
        return new self($document);
    }

    public function id(): string
    {
        return (string) ($this->document['_id'] ?? '');
    }

    /** @return array{manufacturer: ?string, oui: ?string, product_class: ?string, serial_number: ?string} */
    public function deviceId(): array
    {
        $d = $this->document['_deviceId'] ?? [];

        return [
            'manufacturer' => $d['_Manufacturer'] ?? null,
            'oui' => $d['_OUI'] ?? null,
            'product_class' => $d['_ProductClass'] ?? null,
            'serial_number' => $d['_SerialNumber'] ?? null,
        ];
    }

    public function lastInform(): ?CarbonImmutable
    {
        return $this->date('_lastInform');
    }

    public function registered(): ?CarbonImmutable
    {
        return $this->date('_registered');
    }

    /** "InternetGatewayDevice" (TR-098) atau "Device" (TR-181). */
    public function root(): string
    {
        return isset($this->document['Device']) && ! isset($this->document['InternetGatewayDevice'])
            ? 'Device'
            : 'InternetGatewayDevice';
    }

    public function has(string $path): bool
    {
        return $this->node($path) !== null;
    }

    /** Nilai parameter atau null bila belum pernah dibaca dari ONT. */
    public function value(string $path): mixed
    {
        $node = $this->node($path);
        if (! is_array($node) || ! array_key_exists('_value', $node)) {
            return null;
        }

        $value = $node['_value'];

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    public function stringValue(string $path): ?string
    {
        $value = $this->value($path);

        return $value === null ? null : trim((string) $value);
    }

    /** @return array{value: mixed, type: ?string, writable: ?bool, timestamp: ?string}|null */
    public function parameter(string $path): ?array
    {
        $node = $this->node($path);
        if (! is_array($node) || ! array_key_exists('_value', $node)) {
            return null;
        }

        $value = $node['_value'];

        return [
            'value' => is_array($value) ? ($value[0] ?? null) : $value,
            'type' => $node['_type'] ?? (is_array($value) ? ($value[1] ?? null) : null),
            'writable' => $node['_writable'] ?? null,
            'timestamp' => $node['_timestamp'] ?? null,
        ];
    }

    /**
     * Nomor instance objek (anak numerik), mis. instances("...WLANConfiguration") -> [1,2,...,8].
     *
     * @return list<int>
     */
    public function instances(string $path): array
    {
        $node = $this->node($path);
        if (! is_array($node)) {
            return [];
        }

        $ids = [];
        foreach ($node as $key => $child) {
            if (is_array($child) && ctype_digit((string) $key)) {
                $ids[] = (int) $key;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Cari path konkret yang ADA di ONT untuk sebuah template berplaceholder
     * (mis. "...WANConnectionDevice.{wcd}.WANIPConnection.{i}.VLANID").
     * Placeholder dicocokkan dengan instance numerik yang tersedia.
     */
    public function matchTemplate(string $template): ?string
    {
        return $this->matchSegments($this->document, explode('.', $template), []);
    }

    private function matchSegments(mixed $node, array $segments, array $done): ?string
    {
        if ($segments === []) {
            return implode('.', $done);
        }
        if (! is_array($node)) {
            return null;
        }

        $segment = array_shift($segments);
        if (preg_match('/^\{[a-z_]+\}$/i', $segment)) {
            foreach ($node as $key => $child) {
                if (ctype_digit((string) $key) && is_array($child)
                    && ($found = $this->matchSegments($child, $segments, [...$done, (string) $key])) !== null) {
                    return $found;
                }
            }

            return null;
        }

        return array_key_exists($segment, $node)
            ? $this->matchSegments($node[$segment], $segments, [...$done, $segment])
            : null;
    }

    /**
     * Semua parameter (bukan objek) dalam bentuk datar.
     *
     * @return list<array{path: string, value: mixed, type: ?string, writable: ?bool, timestamp: ?string, secret: bool}>
     */
    public function flatten(?string $prefix = null, bool $redact = true): array
    {
        $out = [];
        $start = $prefix ? $this->node($prefix) : $this->document;
        if (! is_array($start)) {
            return [];
        }

        $this->walk($start, $prefix ?? '', $out, $redact);
        usort($out, fn ($a, $b) => strnatcmp($a['path'], $b['path']));

        return $out;
    }

    public function parameterCount(): int
    {
        return count($this->flatten(null, false));
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->document;
    }

    private function walk(array $node, string $path, array &$out, bool $redact): void
    {
        foreach ($node as $key => $child) {
            if (! is_array($child) || str_starts_with((string) $key, '_')) {
                continue;
            }

            $childPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (array_key_exists('_value', $child)) {
                $param = $this->parameter($childPath);
                $secret = Redactor::isSecretKey($childPath);
                $value = $param['value'];
                if ($redact && $secret && $value !== null && $value !== '') {
                    $value = Redactor::MASK;
                }
                $out[] = [
                    'path' => $childPath,
                    'value' => $value,
                    'type' => $param['type'],
                    'writable' => $param['writable'],
                    'timestamp' => $param['timestamp'],
                    'secret' => $secret,
                ];
            }

            $this->walk($child, $childPath, $out, $redact);
        }
    }

    private function node(string $path): mixed
    {
        $node = $this->document;
        foreach (explode('.', trim($path, '.')) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->document[$key] ?? null;

        // GenieACS memakai UTC ("...Z"). Konversi ke zona aplikasi sebelum disimpan,
        // karena Eloquent menyimpan jam apa adanya tanpa offset (bisa selisih 7 jam di WIB).
        return $value ? CarbonImmutable::parse($value)->setTimezone(config('app.timezone')) : null;
    }
}
