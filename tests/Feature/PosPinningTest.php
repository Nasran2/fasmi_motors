<?php

use App\Models\Product;
use App\Models\User;

test('starred products appear first in POS and can be rearranged', function () {
    $user = User::factory()->create();
    $alpha = Product::create(['code' => 'PIN-A', 'name' => 'Alpha Part', 'selling_price' => 10, 'active' => true]);
    $beta = Product::create(['code' => 'PIN-B', 'name' => 'Beta Part', 'selling_price' => 20, 'active' => true]);
    $charlie = Product::create(['code' => 'PIN-C', 'name' => 'Charlie Part', 'selling_price' => 30, 'active' => true]);

    $this->actingAs($user)->withOpenCashier()->get(route('products.index'))->assertOk()
        ->assertSee('Pin Alpha Part in POS');
    $this->post(route('products.pos-pin.toggle', $charlie))->assertRedirect();
    $this->post(route('products.pos-pin.toggle', $beta))->assertRedirect();
    $this->get(route('products.pos-pins.index'))->assertOk()
        ->assertExactJson(['product_ids' => [$charlie->id, $beta->id]]);

    $options = $this->get(route('sales.parts'))->assertOk()
        ->assertSeeText('Arrange pinned products')->viewData('productOptions');
    expect($options->pluck('id')->all())->toBe([$charlie->id, $beta->id, $alpha->id]);

    $this->putJson(route('products.pos-pins.order'), ['product_ids' => [$beta->id, $charlie->id]])
        ->assertOk()->assertJsonPath('success', true);
    expect($this->get(route('sales.parts'))->viewData('productOptions')->pluck('id')->all())
        ->toBe([$beta->id, $charlie->id, $alpha->id]);

    $this->post(route('products.pos-pin.toggle', $beta))->assertRedirect();
    $this->get(route('products.pos-pins.index'))->assertExactJson(['product_ids' => [$charlie->id]]);
    expect($beta->fresh()->pos_pinned)->toBeFalse();
});

test('pin ordering rejects unpinned and other shop products', function () {
    $user = User::factory()->create();
    $pinned = Product::create(['code' => 'PIN-ONE', 'name' => 'Pinned', 'active' => true, 'pos_pinned' => true, 'pos_pin_order' => 1]);
    $unpinned = Product::create(['code' => 'PIN-TWO', 'name' => 'Unpinned', 'active' => true]);
    $otherShop = Product::create(['code' => 'PIN-OTHER', 'name' => 'Other shop', 'active' => true, 'shop_type' => 'helmet', 'pos_pinned' => true, 'pos_pin_order' => 1]);

    $this->actingAs($user)->withOpenCashier()->putJson(route('products.pos-pins.order'), ['product_ids' => [$unpinned->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('product_ids');
    $this->putJson(route('products.pos-pins.order'), ['product_ids' => [$otherShop->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('product_ids');
    $this->post(route('products.pos-pin.toggle', $otherShop))->assertNotFound();
    expect($pinned->fresh()->pos_pin_order)->toBe(1);
});
