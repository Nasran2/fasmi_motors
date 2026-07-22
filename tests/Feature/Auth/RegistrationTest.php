<?php

use App\Models\User;

test('public registration is disabled for the internal workshop system', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
});

test('authorized staff can still create users through user management', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->post(route('user-management.users.store'), [
        'name' => 'Workshop Cashier',
        'username' => 'workshop_cashier',
        'email' => 'cashier@example.com',
        'phone' => '0771234567',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'role_id' => $admin->role_id,
        'active' => 1,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('users', ['username' => 'workshop_cashier']);
});
