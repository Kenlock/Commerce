<?php

use Yab\Quazar\Services\PaymentService;

class PaymentServiceTest extends TestCase
{
    public $service;
    public $user;
    public $role;
    public $cart;

    public function setUp()
    {
        parent::setUp();
        $this->service = app(PaymentService::class);

        $this->user = factory(App\Models\User::class)->create([
            'id' => 1007,
        ]);
        $this->role = factory(App\Models\Role::class)->create([
            'name' => 'customer',
            'label' => 'Customer',
        ]);

        $this->user->roles()->attach($this->role);
        $this->actingAs($this->user);

        factory(\Yab\Quazar\Models\Cart::class)->create();
        factory(\Yab\Quazar\Models\Product::class)->create();
        factory(\Yab\Quazar\Models\Plan::class)->create();
        factory(\Yab\Quazar\Models\Cart::class)->create([
            'id' => 3,
            'customer_id' => 1,
        ]);

        $this->cart = app(\Yab\Quazar\Services\CartService::class);
        $this->cart->addToCart(1, 'product', 1, '{}');

        $this->user->meta = Mockery::mock(App\Models\UserMeta::class);
        $this->user->meta->shouldReceive('getAttribute')->with('stripe_id')->andReturn(null);
        $this->user->meta->shouldReceive('getAttribute')->with('shipping_address')->andReturn('foobar');
        $this->user->meta->shouldReceive('getAttribute')->with('billing_address')->andReturn('foobar');
        $this->user->meta->shouldReceive('charge')
            ->with(Mockery::any(), Mockery::any())
            ->andReturn((object) [
                'id' => 666,
                'created' => 9999,
        ]);
        $this->user->meta->shouldReceive('createAsStripeCustomer')
            ->with(Mockery::any())
            ->andReturn(true);
        $this->user->meta->shouldReceive('updateCard')
            ->with('foobarZoo')
            ->andReturn(function () {
                $this->user->meta->stripe_id = 'forbarZoo';
            });
    }

    public function testPurchaseWithExistingStripeToken()
    {
        $response = $this->service->purchase('foobar', $this->cart);

        $this->assertEquals(get_class($response), 'Illuminate\Http\RedirectResponse');
        $this->seeInDatabase('transactions', [
            'customer_id' => 1007,
            'provider_id' => 666,
            'total' => '99.99',
        ]);
    }

    public function testPurchaseWithNewStripeToken()
    {
        $response = $this->service->purchase('foobarZoo', $this->cart);

        $this->assertEquals(get_class($response), 'Illuminate\Http\RedirectResponse');
        $this->seeInDatabase('transactions', [
            'customer_id' => 1007,
            'provider_id' => 666,
            'total' => '99.99',
        ]);
    }

    public function testPurchaseWithoutStripeToken()
    {
        $this->user->meta->shouldReceive('getAttribute')->with('stripe_id')->andReturn('foobar');
        $response = $this->service->purchase(null, $this->cart);

        $this->assertEquals(get_class($response), 'Illuminate\Http\RedirectResponse');
        $this->seeInDatabase('transactions', [
            'customer_id' => 1007,
            'provider_id' => 666,
            'total' => '99.99',
        ]);
    }

    public function testCreateOrder()
    {
        $transaction = (object) ['id' => 999];
        $items = $this->cart->contents();
        $response = $this->service->createOrder($this->user, $transaction, $items);

        $this->assertEquals($response, true);
        $this->seeInDatabase('orders', [
            'customer_id' => 1007,
            'transaction_id' => 999,
        ]);
    }
}
