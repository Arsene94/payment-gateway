<?php

namespace App\Jobs;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class ProcessPaymentJob implements ShouldQueue
{
    use Queueable;

    public $order;
    public $paymentData;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order, array $paymentData)
    {
        $this->order = $order;
        $this->paymentData = $paymentData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $response = Http::timeout(5)
            ->retry(3, 200)
            ->post(route('mock-stripe.charge'), $this->paymentData);

        if ($response->successful()) {
            $this->order->update([
                'status' => OrderStatusEnum::PAID,
                'transaction_id' => $response->json('transaction_id'),
            ]);
        }
    }
}
