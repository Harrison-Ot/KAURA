<?php

namespace App\Http\Middleware;

use App\Services\AccessAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'owner') {
            AccessAudit::record('access.denied', $request->user(), null, 'Owner access required: '.$request->method().' '.$request->path());
            abort(403);
        }

        return $next($request);
    }
}
