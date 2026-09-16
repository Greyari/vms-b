<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deploy and monitoring probe that proves the database answers. /up only
 * proves PHP is alive, which is exactly what hides a dead database.
 */
class HealthDbController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            Log::error('Health check could not reach the database.', ['exception' => $e::class]);

            return response()->json(['status' => 'error'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
