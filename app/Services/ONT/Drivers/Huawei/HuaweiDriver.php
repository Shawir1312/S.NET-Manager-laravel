<?php

namespace App\Services\ONT\Drivers\Huawei;

use App\Services\ONT\Drivers\AbstractDriver;

/**
 * Huawei: binding berupa FLAG X_HW_LANBIND.Lan{n}Enable / SSID{n}Enable (binding_method = flags),
 * service list X_HW_SERVICELIST, VLAN X_HW_VLAN.
 */
class HuaweiDriver extends AbstractDriver {}
