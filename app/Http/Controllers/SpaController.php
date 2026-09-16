<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Answers client-side routes (/visits/create, /master/visitors, …) with the
 * SPA's index.html so a browser refresh works. Apache serves real files in
 * public/ (the JS and CSS under assets/) before a request ever reaches Laravel.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $index = (string) config('vms.spa_index');

        abort_if($request->is('api', 'api/*') || ! is_file($index), 404);

        return response()->file($index, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
