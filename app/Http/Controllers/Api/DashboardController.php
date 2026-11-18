<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /** GET /api/dashboard/stats */
    public function __invoke(DashboardStatsService $stats): JsonResponse
    {
        return response()->json($stats->summary());
    }
}
