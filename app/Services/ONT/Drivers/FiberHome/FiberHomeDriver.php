<?php

namespace App\Services\ONT\Drivers\FiberHome;

use App\Services\ONT\Drivers\AbstractDriver;

/**
 * FiberHome: binding berupa string list di X_FH_LanInterface, VLAN di WANIPConnection.VLANEnable/VLANID,
 * service list X_FH_ServiceList. Path lengkap: FiberHomeParameters / tabel ont_parameter_mappings.
 */
class FiberHomeDriver extends AbstractDriver {}
