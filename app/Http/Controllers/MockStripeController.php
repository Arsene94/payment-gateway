<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MockStripeController extends Controller
{
    /**
     * Simulate a mock API endpoint for processing payments.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();

        if (empty($payload['order_id'])) {
            return $this->respondWithError('Invalid payload', 400);
        }

        $order = Order::find($payload['order_id']);
        if (!$order) {
            return $this->respondWithError('Order not found', 404);
        }

        $status = $this->simulatePaymentStatus();
        $this->updateOrderStatus($order, $status);

        return $status === OrderStatusEnum::PAID
            ? $this->respondWithSuccess($status->label(), $status->value, 200)
            : $this->respondWithError($status->label(), 400, $status->value);
    }

    /**
     * Simulate payment status based on random logic.
     *
     * @return OrderStatusEnum
     */
    private function simulatePaymentStatus(): OrderStatusEnum
    {
        return rand(0, 1) ? OrderStatusEnum::PAID : OrderStatusEnum::FAILED;
    }

    /**
     * Update the status of the order.
     *
     * @param Order $order
     * @param OrderStatusEnum $status
     * @return void
     */
    private function updateOrderStatus(Order $order, OrderStatusEnum $status): void
    {
        $order->update(['status' => $status->value]);
        $transaction = $order->transaction;
        $transaction->status = $status->value;
        $transaction->save();


        Log::info('Order status updated', [
            'order_id' => $order->id,
            'status' => $status->value,
            'label' => $status->label(),
        ]);
    }

    /**
     * Respond with a success message.
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
     * Respond with an error message.
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
