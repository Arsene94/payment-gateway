<?php

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentProcessingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful payment processing.
     */
    public function testSuccessfulPaymentProcessing()
    {
        Http::fake([
            route('mock-stripe.charge') => Http::response([
                'status' => 'paid',
            ], 200),
            route('generate-token') => Http::response([
                'access_token' => 'test-token',
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'amount' => '$100',
            'status' => OrderStatusEnum::PENDING,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'payment_provider' => 'stripe',
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/pay");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Payment processed successfully.',
            ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatusEnum::PAID,
        ]);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_provider' => 'stripe',
            'status' => 'paid',
        ]);
    }

    /**
     * Test payment processing handles invalid requests gracefully.
     */
    public function testPaymentProcessingFailsForInvalidOrder()
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/orders/999/pay'); // Non-existent order ID

        $response->assertStatus(404); // Should return 404 error
        $response->assertJsonFragment([
            'message' => 'No query results for model [App\\Models\\Order] 999',
            'exception' => 'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
        ]);
    }
}
