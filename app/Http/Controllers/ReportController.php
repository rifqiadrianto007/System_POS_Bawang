<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceReportRequest;
use App\Http\Requests\SalesByProductReportRequest;
use App\Http\Requests\ShiftReportRequest;
use App\Http\Requests\StockReportRequest;
use App\Http\Resources\FinanceReportResource;
use App\Http\Resources\OperationalReportResource;
use App\Services\ReportService;

class ReportController extends Controller
{
    public function finance(
        FinanceReportRequest $request,
        ReportService $reportService
    ): FinanceReportResource {
        return new FinanceReportResource($reportService->finance($request->validated()));
    }

    public function salesByProduct(
        SalesByProductReportRequest $request,
        ReportService $reportService
    ): OperationalReportResource {
        return new OperationalReportResource($reportService->salesByProduct($request->validated()));
    }

    public function stock(
        StockReportRequest $request,
        ReportService $reportService
    ): OperationalReportResource {
        return new OperationalReportResource($reportService->stock($request->validated()));
    }

    public function shifts(
        ShiftReportRequest $request,
        ReportService $reportService
    ): OperationalReportResource {
        return new OperationalReportResource($reportService->shifts($request->validated()));
    }
}
