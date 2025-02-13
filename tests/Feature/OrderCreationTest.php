<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful order creation.
     */
    public function testOrderCreation()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/orders', [
            'amount' => '$100',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Order created successfully!']);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'amount' => '$100',
        ]);

        $this->assertDatabaseHas('transactions', [
            'payment_provider' => 'stripe',
            'status' => 'pending',
        ]);
    }

    /**
     * Test order creation with invalid amount format.
     */
    public function testOrderCreationFailsWithInvalidAmount()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/orders', [
            'amount' => 'INVALID',
        ]);

        $response->assertStatus(422); // Validation failure
        $response->assertJsonValidationErrors(['amount']); // Ensure `amount` validation fails
    }
}
