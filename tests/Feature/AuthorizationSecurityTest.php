<?php

use App\Models\Role;
use App\Models\User;
use App\Support\RoutePermission;

test('users cannot open or mutate modules outside their backend permissions', function () {
    $role = Role::create(['name' => 'Dashboard Only', 'permissions' => ['dashboard.view']]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('products.index'))->assertForbidden();
    $this->actingAs($user)->post(route('products.stores.store'), ['name' => 'Unauthorized Store'])->assertForbidden();
    $this->assertDatabaseMissing('stores', ['name' => 'Unauthorized Store']);
});

test('permission middleware protects sensitive user and settings actions', function () {
    $role = Role::create(['name' => 'Stock Viewer', 'permissions' => ['product.view']]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->get(route('products.index'))->assertOk();
    $this->actingAs($user)->get(route('user-management.permissions.index'))->assertForbidden();
    $this->actingAs($user)->post(route('system-settings.create-backup'))->assertForbidden();
});

test('inactive authenticated sessions are terminated before protected pages load', function () {
    $user = User::factory()->create(['active' => false]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

test('shop switching requires a csrf protected post request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/shop-switch/helmet')->assertMethodNotAllowed();
    $this->actingAs($user)->post(route('shop.switch', 'helmet'))->assertRedirect();
    expect(session('shop_mode'))->toBe('helmet');
});

test('every route using the permission middleware has an explicit permission mapping', function () {
    $unmapped = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('route.permission', $route->middleware(), true))
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->filter(fn (string $name) => RoutePermission::for(
            $name,
            $name === 'workspace.page' ? 'dashboard' : null,
        ) === null)
        ->values()
        ->all();

    expect($unmapped)->toBeEmpty('Unmapped protected routes: '.implode(', ', $unmapped));
});
