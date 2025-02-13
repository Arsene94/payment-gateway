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
    public function index() {
        return view('welcome');
    }

    public function store(Request $request) {
        $request->validate([
            'amount' => [
                'required',
                'string',
                'regex:/^[A-Za-z$]{1,3}\d+(\.\d{1,2})?$/',
            ],
        ], [
            'amount.regex' => 'The amount format must include a currency and numeric value, e.g., $100 or RON500.',
        ]);

        $user = User::first();

        if (!$user) {
            return redirect()
                ->back()
                ->withErrors(['amount' => 'No user found!'])->withInput();
        }

        $order = Order::create([
            'user_id' => $user->id,
            'amount' => $request->input('amount'),
            'status' => OrderStatusEnum::PENDING->value,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'payment_provider' => 'stripe',
            'status' => OrderStatusEnum::PENDING->value,
        ]);

        return redirect()->route('orders.index')->with('success', 'Order created successfully! You can pay <a href="' . route('orders.pay', $order->id) . '">Here</a>');
    }

    public function pay(Order $order) {
        return view('pay', compact('order'));
    }

    public function paymentStore(Order $order, Request $request) {
        $transaction = $order->transaction;
        $amount = extractAmount($order->amount);
        $currency = extractCurrency($order->amount);
        $user = User::first();

        try {
            $token = JWTAuth::fromUser($user);

            $paymentData = [
                'amount' => $amount,
                'currency' => $currency,
                'order_id' => $order->id,
                'payment_method' => 'card_visa',
            ];

            $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ])
                ->post(route('mock-stripe.charge'), $paymentData);

            if ($response->successful()) {
                $transaction->response_data = $response->json();
                $transaction->status = OrderStatusEnum::PAID->value;
                $transaction->save();

                $order->update(['status' => OrderStatusEnum::PAID->value]);

                return redirect()
                    ->route('orders.pay', $order)
                    ->with('success', 'Payment processed successfully.');
            }

            if ($response->failed()) {
                $transaction->response_data = $response->reason();
                $transaction->status = OrderStatusEnum::FAILED->value;
                $transaction->save();

                $order->update(['status' => OrderStatusEnum::FAILED->value]);
                return redirect()
                    ->back()
                    ->withErrors(['error' => 'An error occurred: ' . $response->reason()]);
            }
        } catch (\Exception $exception) {
            Log::error('Payment processing failed', [
                'order_id' => $order->id,
                'error' => $exception->getMessage(),
            ]);
            $transaction->response_data = $exception->getMessage();
            $transaction->status = OrderStatusEnum::FAILED->value;
            $transaction->save();

            $order->update(['status' => OrderStatusEnum::FAILED->value]);
            return redirect()
                ->back()
                ->withErrors(['error' => 'An error occurred: ' . $exception->getMessage()]);
        }

    }

}
