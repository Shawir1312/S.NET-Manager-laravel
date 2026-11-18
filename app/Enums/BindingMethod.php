<?php

namespace App\Enums;

enum BindingMethod: string
{
    /** Satu parameter string berisi path interface dipisah koma (mis. FiberHome X_FH_LanInterface). */
    case StringList = 'string_list';
    /** Flag boolean per port (mis. Huawei X_HW_LANBIND.Lan1Enable). */
    case Flags = 'flags';
    /** Path WAN ikut dikirim bersama LAN/WLAN (khusus ZTE F670L). */
    case WanPlusLan = 'wan_plus_lan';
    case Unknown = 'unknown';
}
