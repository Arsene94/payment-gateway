<?php

namespace App\Http\Controllers\API;

use App\Enums\OrderStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\TransactionResource;
use App\Jobs\ProcessPaymentJob;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    const PAYMENT_PROVIDER = 'stripe';
    const PAYMENT_METHOD = 'card_visa';

    /**
     * Create a new order along with its associated transaction.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $this->validateOrder($request);

        $order = $this->createOrder($request->input('amount'));
        $this->createTransaction($order);

        return $this->buildResponse('success', 'Order created successfully!');
    }

    public function show(int $id) {
        $order = Order::with('user')->findOrFail($id);

        return new OrderResource($order);
    }

    public function showTransaction(int $id) {
        $transaction = Transaction::with('order')->findOrFail($id);

        return new TransactionResource($transaction);
    }

    /**
     * Process the payment for the specified order.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function processPayment(Request $request, int $id)
    {
        $order = Order::with('transaction')->findOrFail($id);
        $transaction = $order->transaction;

        $paymentData = $this->preparePaymentData($order);

        try {
            $token = $request->header('Authorization');

            $response = $this->makePaymentRequest($token, $paymentData);

            if ($response->successful()) {
                $this->handleSuccessfulPayment($order, $transaction, $response);
                return $this->buildResponse('success', 'Payment processed successfully.');
            }

            $this->retryPaymentLater($order, $paymentData);
        } catch (\Exception $e) {
            $this->handleFailedPayment($order, $transaction, $e);
            return $this->buildResponse('error', 'An error occurred: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Validate the request data for creating an order.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    protected function validateOrder(Request $request)
    {
        $rules = [
            'amount' => 'required|string|regex:/^[A-Za-z$]{1,3}\d+$/',
        ];

        $messages = [
            'amount.regex' => 'The amount format should be like $100 or RON5000.00.',
        ];

        $request->validate($rules, $messages);
    }

    /**
     * Create a new order.
     *
     * @param string $amount
     * @return \App\Models\Order
     */
    protected function createOrder(string $amount): Order
    {
        return Order::create([
            'user_id' => auth()->id(),
            'amount' => $amount,
            'status' => OrderStatusEnum::PENDING->value,
        ]);
    }

    /**
     * Create a new transaction for the order.
     *
     * @param \App\Models\Order $order
     * @return void
     */
    protected function createTransaction(Order $order)
    {
        Transaction::create([
            'order_id' => $order->id,
            'payment_provider' => self::PAYMENT_PROVIDER,
            'status' => OrderStatusEnum::PENDING->value,
        ]);
    }

    /**
     * Prepare payment data from the order for the payment request.
     *
     * @param \App\Models\Order $order
     * @return array
     */
    protected function preparePaymentData(Order $order): array
    {
        return [
            'amount' => extractAmount($order->amount),
            'currency' => extractCurrency($order->amount),
            'order_id' => $order->id,
            'payment_method' => self::PAYMENT_METHOD,
        ];
    }

    /**
     * Make the payment request to the third-party payment provider.
     *
     * @param string $token
     * @param array $paymentData
     * @return \Illuminate\Http\Client\Response
     */
    protected function makePaymentRequest(string $token, array $paymentData)
    {
        return Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            ])
            ->post(route('mock-stripe.charge'), $paymentData);
    }

    /**
     * Handle a successful payment.
     *
     * @param \App\Models\Order $order
     * @param \App\Models\Transaction $transaction
     * @param \Illuminate\Http\Client\Response $response
     * @return void
     */
    protected function handleSuccessfulPayment(Order $order, Transaction $transaction, $response)
    {
        $transaction->update([
            'response_data' => $response->json(),
            'status' => OrderStatusEnum::PAID->value,
        ]);

        $order->update([
            'status' => OrderStatusEnum::PAID->value,
        ]);
    }

    /**
     * Handle a failed payment and retry later.
     *
     * @param \App\Models\Order $order
     * @param \App\Models\Transaction $transaction
     * @param \Exception $exception
     * @return void
     */
    protected function handleFailedPayment(Order $order, Transaction $transaction, \Exception $exception)
    {
        Log::error('Payment processing failed', [
            'order_id' => $order->id,
            'error' => $exception->getMessage(),
        ]);

        $this->retryPaymentLater($order, $this->preparePaymentData($order));

        $transaction->update([
            'response_data' => $exception->getMessage(),
            'status' => OrderStatusEnum::FAILED->value,
        ]);

        $order->update([
            'status' => OrderStatusEnum::FAILED->value,
        ]);
    }

    /**
     * Retry payment by dispatching a payment job for delayed processing.
     *
     * @param \App\Models\Order $order
     * @param array $paymentData
     * @return void
     */
    protected function retryPaymentLater(Order $order, array $paymentData)
    {
        Log::info('Payment failed, scheduling retry...', ['order_id' => $order->id]);
        ProcessPaymentJob::dispatch($order, $paymentData)->delay(now()->addMinutes(5));
    }

    /**
     * Build a consistent JSON response.
     *
     * @param string $status
     * @param string $message
     * @param int $statusCode
     * @return \Illuminate\Http\JsonResponse
     */
    protected function buildResponse(string $status, string $message, int $statusCode = 200)
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
        ], $statusCode);
    }
}
