<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MockStripeController extends Controller
{
    /**
     * Handle the mock webhook API for processing payments.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request)
    {
        $validatedData = $this->validateWebhookRequest($request);

        $order = Order::with('transaction')->find($validatedData['order_id']);
        if (!$order) {
            return $this->respondWithError('Order not found', 404);
        }

        $status = $this->simulatePaymentStatus();
        $this->updateOrderAndTransactionStatus($order, $status);

        return match ($status) {
            OrderStatusEnum::PAID => $this->respondWithSuccess('Payment succeeded.', $status->value),
            default => $this->respondWithError('Payment failed.', 400, $status->value),
        };
    }

    /**
     * Validate the incoming webhook request.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    private function validateWebhookRequest(Request $request): array
    {
        return $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
        ]);
    }

    /**
     * Simulate payment status using random logic.
     *
     * @return \App\Enums\OrderStatusEnum
     */
    private function simulatePaymentStatus(): OrderStatusEnum
    {
        return rand(0, 1) ? OrderStatusEnum::PAID : OrderStatusEnum::FAILED;
    }

    /**
     * Update the status of the order and its transaction.
     *
     * @param \App\Models\Order $order
     * @param \App\Enums\OrderStatusEnum $status
     * @return void
     */
    private function updateOrderAndTransactionStatus(Order $order, OrderStatusEnum $status): void
    {
        $order->update(['status' => $status->value]);

        if ($order->transaction) {
            $order->transaction->update(['status' => $status->value]);
        }

        Log::info('Order and transaction status updated.', [
            'order_id' => $order->id,
            'transaction_id' => $order->transaction?->id,
            'status' => $status->value,
            'label' => $status->label(),
        ]);
    }

    /**
     * Respond with a success JSON response.
     *
     * @param string $message
     * @param string $status
     * @param int $statusCode
     * @return \Illuminate\Http\JsonResponse
     */
    private function respondWithSuccess(string $message, string $status, int $statusCode = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => $message,
            'status' => $status,
        ], $statusCode);
    }

    /**
     * Respond with an error JSON response.
     *
     * @param string $message
     * @param int $statusCode
     * @param string|null $status
     * @return \Illuminate\Http\JsonResponse
     */
    private function respondWithError(string $message, int $statusCode = 400, ?string $status = null): \Illuminate\Http\JsonResponse
    {
        $response = ['message' => $message];
        if ($status) {
            $response['status'] = $status;
        }

        return response()->json($response, $statusCode);
    }
}
