<?php

namespace App\Services\ONT;

use App\Models\OntCapability;
use App\Models\OntDevice;
use App\Models\OntModel;
use App\Models\OntParameterMapping;
use App\Services\GenieACS\ParameterTree;
use App\Services\ONT\Exceptions\ParameterUnavailableException;
use Illuminate\Support\Collection;

/** Semua yang dibutuhkan driver tentang SATU perangkat: data ONT + model + mapping. */
final class DeviceContext
{
    /** @param Collection<string, OntParameterMapping> $mappings */
    public function __construct(
        public readonly OntDevice $device,
        public readonly ParameterTree $tree,
        public readonly DetectionResult $detection,
        private readonly Collection $mappings,
    ) {}

    public static function build(OntDevice $device, ParameterTree $tree, DetectionResult $detection): self
    {
        $mappings = $detection->model
            ? $detection->model->parameterMappings()->get()->keyBy('key')
            : collect();

        return new self($device, $tree, $detection, $mappings);
    }

    public function model(): ?OntModel
    {
        return $this->detection->model;
    }

    public function capability(): ?OntCapability
    {
        return $this->detection->model?->capability;
    }

    public function modelLabel(): string
    {
        return trim(($this->detection->vendor?->label() ?? $this->detection->manufacturer).' '.($this->detection->model?->model_name ?? $this->detection->productClass));
    }

    public function root(): string
    {
        return $this->tree->root();
    }

    public function hasMapping(string $key): bool
    {
        return $this->mappings->has($key);
    }

    public function mapping(string $key): OntParameterMapping
    {
        return $this->mappings->get($key) ?? throw new ParameterUnavailableException($key, $this->modelLabel());
    }

    /** @param array<string, int|string> $vars */
    public function path(string $key, array $vars = []): string
    {
        return $this->mapping($key)->resolvePath($vars);
    }

    public function type(string $key): string
    {
        return $this->mapping($key)->value_type ?: 'xsd:string';
    }

    public function verified(string $key): bool
    {
        return (bool) $this->mappings->get($key)?->verified;
    }

    /** Nilai vendor untuk label logis (mis. service list "Other" -> "OTHER"). */
    public function mapValue(string $key, string $label): string
    {
        $map = $this->mappings->get($key)?->value_map ?? [];

        return (string) ($map[$label] ?? $label);
    }

    /** Baca nilai saat ini lewat mapping (null bila mapping/parameter tidak ada). */
    public function read(string $key, array $vars = []): mixed
    {
        if (! $this->hasMapping($key)) {
            return null;
        }

        try {
            return $this->tree->value($this->path($key, $vars));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
