<?php

namespace App\Http\Controllers\API;

use App\Enums\OrderStatusEnum;
use App\Http\Controllers\Controller;
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
     * Create a new order and associated transaction.
     */
    public function store(Request $request)
    {
        $this->validateOrder($request);

        $order = $this->createOrder($request->input('amount'));
        $this->createTransaction($order);

        return response()->json(['message' => 'Order created successfully!']);
    }

    /**
     * Process payment for a given order.
     */
    public function processPayment(Request $request, $id)
    {

        $order = Order::with('transaction')->findOrFail($id);

        $transaction = $order->transaction;
        $paymentData = $this->preparePaymentData($order);

        try {
            $token = $request->header('Authorization');
            $response = $this->makePaymentRequest($token, $paymentData);

            if ($response->successful()) {
                $this->handleSuccessfulPayment($order, $transaction, $response);
                return buildResponse('success', 'Payment processed successfully.');
            }

            $this->retryPaymentLater($order, $paymentData);
        } catch (\Exception $e) {
            $this->handleFailedPayment($order, $transaction, $e);
            return buildResponse('error', 'An error occurred: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Validate the store request.
     */
    protected function validateOrder(Request $request)
    {
        $rules = [
            'amount' => [
                'required',
                'string',
                'regex:/^[A-Za-z$]{1,3}\d+$/',
            ],
        ];

        $messages = [
            'amount.regex' => 'Amount format should be in the format of $100 or RON5000.00',
        ];

        $request->validate($rules, $messages);
    }

    /**
     * Create a new order.
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
     * Create a new transaction for the given order.
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
     * Prepare payment data based on the order.
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
     * Make the payment request to the provider.
     */
    protected function makePaymentRequest(string $token, array $paymentData)
    {
        return Http::timeout(50)
            ->retry(2, 10000)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            ])
            ->post(route('mock-stripe.charge'), $paymentData);
    }

    /**
     * Handle successful payment updates.
     */
    /**
     * Handle successful payment updates.
     */
    protected function handleSuccessfulPayment(Order $order, Transaction $transaction, $response)
    {
        $transaction->response_data = $response->json();
        $transaction->status = OrderStatusEnum::PAID->value;
        $transaction->save();

        $order->update(['status' => OrderStatusEnum::PAID->value]);
    }

    /**
     * Handle failed payment and retry later.
     */
    protected function handleFailedPayment(Order $order, Transaction $transaction, \Exception $exception)
    {
        Log::error('Payment processing failed', [
            'order_id' => $order->id,
            'error' => $exception->getMessage(),
        ]);

        $this->retryPaymentLater($order, $this->preparePaymentData($order));

        $transaction->response_data = $exception->getMessage();
        $transaction->status = OrderStatusEnum::FAILED->value;
        $transaction->save();

        $order->update(['status' => OrderStatusEnum::FAILED->value]);
    }

    /**
     * Retry payment later by dispatching a delayed job.
     */
    protected function retryPaymentLater(Order $order, array $paymentData)
    {
        Log::info('Payment failed, retrying later...', ['order_id' => $order->id]);
        ProcessPaymentJob::dispatch($order, $paymentData)->delay(now()->addMinutes(5));
    }
}
