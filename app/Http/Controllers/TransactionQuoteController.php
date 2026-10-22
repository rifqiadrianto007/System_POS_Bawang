<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteTransactionRequest;
use App\Http\Resources\TransactionQuoteResource;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class TransactionQuoteController extends Controller
{
    public function __invoke(QuoteTransactionRequest $request, PricingService $pricingService): JsonResponse
    {
        try {
            $quote = $pricingService->quoteCart(
                $request->string('customer_category')->toString(),
                $request->array('items'),
                $request->has('final_total') ? $request->integer('final_total') : null,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return (new TransactionQuoteResource($quote))->response();
    }
}
