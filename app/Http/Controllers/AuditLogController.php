<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __invoke(
        AuditLogIndexRequest $request,
        AuditLogService $auditLogService
    ): AnonymousResourceCollection {
        return AuditLogResource::collection($auditLogService->list($request->validated()));
    }
}
