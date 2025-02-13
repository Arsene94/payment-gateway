<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrderController extends Controller
{
    /**
     * Show the welcome page.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('welcome');
    }

    /**
     * Store a new order and associated transaction in the system.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|string|regex:/^[A-Za-z$]{1,3}\d+(\.\d{1,2})?$/',
        ], [
            'amount.regex' => 'The amount format must include a currency and numeric value, e.g., $100 or RON500.',
        ]);

        $user = User::firstOrFail();

        $order = $this->createOrderWithTransaction($user, $request->input('amount'));

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order created successfully! You can pay <a href="' . route('orders.pay', $order->id) . '">Here</a>');
    }

    /**
     * Show the payment page for a specific order.
     *
     * @param \App\Models\Order $order
     * @return \Illuminate\View\View
     */
    public function pay(Order $order)
    {
        return view('pay', compact('order'));
    }

    /**
     * Process payment for a given order.
     *
     * @param \App\Models\Order $order
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function paymentStore(Order $order, Request $request)
    {
        $transaction = $order->transaction;

        $amount = extractAmount($order->amount);
        $currency = extractCurrency($order->amount);

        $user = User::firstOrFail();
        $token = JWTAuth::fromUser($user);

        // Payment data payload
        $paymentData = [
            'amount' => $amount,
            'currency' => $currency,
            'order_id' => $order->id,
            'payment_method' => 'card_visa',
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])->post(route('mock-stripe.charge'), $paymentData);

            return $this->handlePaymentResponse($response, $order, $transaction);
        } catch (\Exception $exception) {
            Log::error('Payment processing failed', [
                'order_id' => $order->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->handleFailedPayment($order, $transaction, $exception->getMessage());
        }
    }

    /**
     * Create an order with a transaction.
     *
     * @param \App\Models\User $user
     * @param string $amount
     * @return \App\Models\Order
     */
    private function createOrderWithTransaction(User $user, string $amount)
    {
        $order = Order::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'status' => OrderStatusEnum::PENDING->value,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'payment_provider' => 'stripe',
            'status' => OrderStatusEnum::PENDING->value,
        ]);

        return $order;
    }

    /**
     * Handle the response from the external payment API.
     *
     * @param \Illuminate\Http\Client\Response $response
     * @param \App\Models\Order $order
     * @param \App\Models\Transaction $transaction
     * @return \Illuminate\Http\RedirectResponse
     */
    private function handlePaymentResponse($response, Order $order, Transaction $transaction)
    {
        if ($response->successful()) {
            $transaction->update([
                'response_data' => $response->json(),
                'status' => OrderStatusEnum::PAID->value,
            ]);

            $order->update(['status' => OrderStatusEnum::PAID->value]);

            return redirect()
                ->route('orders.pay', $order)
                ->with('success', 'Payment processed successfully.');
        }

        if ($response->failed()) {
            return $this->handleFailedPayment($order, $transaction, $response->reason());
        }
    }

    /**
     * Handle failed payment by updating transaction and order status.
     *
     * @param \App\Models\Order $order
     * @param \App\Models\Transaction $transaction
     * @param string $errorMessage
     * @return \Illuminate\Http\RedirectResponse
     */
    private function handleFailedPayment(Order $order, Transaction $transaction, string $errorMessage)
    {
        $transaction->update([
            'response_data' => $errorMessage,
            'status' => OrderStatusEnum::FAILED->value,
        ]);

        $order->update(['status' => OrderStatusEnum::FAILED->value]);

        return redirect()
            ->back()
            ->withErrors(['error' => 'An error occurred: ' . $errorMessage]);
    }
}
