<?php

namespace App\Services\ONT;

use App\Enums\Vendor;
use App\Models\OntModel;
use App\Services\GenieACS\ParameterTree;

/**
 * Identifikasi vendor & model dari data GenieACS:
 * DeviceID.Manufacturer, ProductClass, SoftwareVersion, HardwareVersion.
 */
class ONTDetector
{
    public function detect(ParameterTree $tree): DetectionResult
    {
        $id = $tree->deviceId();
        $root = $tree->root();
        $software = $tree->stringValue($root.'.DeviceInfo.SoftwareVersion');
        $hardware = $tree->stringValue($root.'.DeviceInfo.HardwareVersion');

        $vendor = Vendor::fromManufacturer($id['manufacturer']);
        $model = $vendor ? $this->findModel($vendor, $id['product_class'], $software) : null;

        return new DetectionResult($vendor, $model, $id['manufacturer'], $id['product_class'], $software, $hardware);
    }

    /**
     * Pilih model paling spesifik: pola firmware yang cocok lebih diutamakan daripada "*".
     */
    public function findModel(Vendor $vendor, ?string $productClass, ?string $softwareVersion): ?OntModel
    {
        if (blank($productClass)) {
            return null;
        }

        $candidates = OntModel::with('capability')
            ->where('vendor', $vendor)
            ->where('product_class', $productClass)
            ->get()
            ->filter(fn (OntModel $m) => $m->matchesFirmware($softwareVersion));

        return $candidates
            ->sortBy(fn (OntModel $m) => $m->firmware_pattern === '*' ? 1 : 0)
            ->first();
    }
}
