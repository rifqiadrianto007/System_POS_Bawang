<?php

namespace App\Http\Controllers;

use App\Http\Resources\StockSummaryResource;
use App\Services\StockService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    public function __invoke(StockService $stockService): AnonymousResourceCollection
    {
        return StockSummaryResource::collection($stockService->summaries());
    }
}
