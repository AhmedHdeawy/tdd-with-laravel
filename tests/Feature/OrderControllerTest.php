<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Stock;
use App\Models\Product;
use App\Models\Ingredient;
use App\Jobs\UpdateTheStock;
use App\Events\LowStockEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Database\Eloquent\Model;
use App\Notifications\LowStockNotification;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\StockService\StockServiceContract;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private Model $product;
    private Model $beef;
    private Model $cheese;
    private Model $onion;


    public function test_place_new_order_request_without_payload_should_fail() : void
    {
        $this->postJson('/api/orders/place-order')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products']);
    }

    public function test_place_new_order_request_without_product_id_should_fail() : void
    {
        $data = [
            'products' => [
                ['quantity' => 2]
            ]
        ];

        $this->postJson('/api/orders/place-order', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.product_id']);
    }
    
    public function test_place_new_order_request_without_quantity_should_fail() : void
    {
        $data = [
            'products' => [
                ['product_id' => 2]
            ]
        ];

        $this->postJson('/api/orders/place-order', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.quantity']);
    }

    public function test_place_new_order_with_low_quantity_should_fail_with_410(): void
    {
        // Create ingredients and products
        $this->seedAndReturnProductWithIngredient();

        // Make an order request payload
        $orderPayload = [
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 500]
            ]
        ];

        // Make a POST request to the placeOrder action
        $response = $this->postJson('/api/orders/place-order', $orderPayload);

        // Assert the response and database changes
        $response->assertStatus(Response::HTTP_GONE);
    }

    public function test_place_new_order_successfully(): void
    {
        // Create ingredients and products
        $this->seedAndReturnProductWithIngredient();

        // Make an order request payload
        $orderPayload = [
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 2]
            ]
        ];

        // Make a POST request to the placeOrder action
        $response = $this->postJson('/api/orders/place-order', $orderPayload);
        $createdOrder = $response->getOriginalContent();

        // Assert the response and database changes
        $response->assertStatus(Response::HTTP_CREATED);
        $this->assertDatabaseHas('orders', ['id' => $createdOrder->id]);
        $this->assertDatabaseHas('order_items', ['id' => 1, 'product_id' => $this->product->id, 'order_id' => $createdOrder->id, 'quantity' => 2]);
    }
    
    public function test_stock_updated_after_order_created(): void
    {
        Queue::fake();

        // Create ingredients and products
        $this->seedAndReturnProductWithIngredient();
        $orderPayload = ['products' => [['product_id' => $this->product->id, 'quantity' => 2]]];
        $this->postJson('/api/orders/place-order', $orderPayload);
        
        // Get the created order
        $createdOrder = Order::latest()->first();
        
        // Assert update stock job has dispatched with the correct order
        Queue::assertPushed(UpdateTheStock::class);
        Queue::assertPushed(function (UpdateTheStock $job) use ($createdOrder) {
            return $job->orderId === $createdOrder->id;
        });

        // Run the update stock job
        $this->runUpdateStockJob($createdOrder->id);

        // Assert database changes
        $this->assertDatabaseHas('stocks', ['ingredient_id' => $this->beef->id, 'current_stock' => 19700]); // 20000 - (150 * 2)
        $this->assertDatabaseHas('stocks', ['ingredient_id' => $this->cheese->id, 'current_stock' => 4940]); // 5000 - (30 * 2)
        $this->assertDatabaseHas('stocks', ['ingredient_id' => $this->onion->id, 'current_stock' => 960]); // 1000 - (20 * 2)
    }
    
    public function test_low_stock_event_has_fired(): void
    {
        Event::fake();

        // Create ingredients and products
        $this->seedAndReturnProductWithIngredient();
        $orderPayload = ['products' => [['product_id' => $this->product->id, 'quantity' => 10]]];
        $this->postJson('/api/orders/place-order', $orderPayload);
        
        // Get the created order
        $createdOrder = Order::latest()->first();
        
        // Run the update stock job
        $this->runUpdateStockJob($createdOrder->id);

        Event::assertDispatched((LowStockEvent::class));
    }

    public function test_low_stock_notification_has_sent(): void
    {
        Notification::fake();

        // Create ingredients and products
        $this->seedAndReturnProductWithIngredient();
        $orderPayload = ['products' => [['product_id' => $this->product->id, 'quantity' => 40]]];
        $this->postJson('/api/orders/place-order', $orderPayload);
        
        Notification::assertSentOnDemand(
            LowStockNotification::class,
            function (LowStockNotification $notification, array $channels, object $notifiable) {
                return $notifiable->routes['mail'] === config('services.merchant_mail');
            }
        );

    }

    private function runUpdateStockJob(int $orderId) : void
    {
        // Resolve the stock service
        $stockServiceFake = $this->app->make(StockServiceContract::class);
        
        // Run the update stock job
        $job = new UpdateTheStock($orderId);
        $job->handle($stockServiceFake);
    }

    private function seedAndReturnProductWithIngredient(): void
    {
        // Create ingredients and products
        $this->beef = Ingredient::factory()->create(['name' => 'Beef']);
        $this->beef->stock()->save($this->saveNewStock(20000));
        
        $this->cheese = Ingredient::factory()->create(['name' => 'Cheese']);
        $this->cheese->stock()->save($this->saveNewStock(5000));
        
        $this->onion = Ingredient::factory()->create(['name' => 'Onion']);
        $this->onion->stock()->save($this->saveNewStock(1000));
        
        $product =  Product::factory()->create(['name' => 'Burger']);

        $product->ingredients()->attach([
            $this->beef->id => ['quantity' => 150],
            $this->cheese->id => ['quantity' => 30],
            $this->onion->id => ['quantity' => 20],
        ]);

        $this->product = $product;
    }

    public function saveNewStock(int $quantity) : Stock
    {
        $stock = new Stock();
        $stock->initial_stock = $quantity;
        $stock->current_stock = $quantity;

        return $stock;
    }
}
