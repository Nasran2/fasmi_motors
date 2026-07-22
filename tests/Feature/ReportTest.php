<?php

use App\Models\User;

test('reports page uses today when no date filters are supplied', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSeeText('Reports')
        ->assertSee(today()->toDateString())
        ->assertSee('date_range=today', escape: false);
});

test('reports page accepts a valid custom date range', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports.index', ['from' => '2026-06-01', 'to' => '2026-06-15']))
        ->assertOk()
        ->assertSee('2026-06-01')
        ->assertSee('2026-06-15');
});

test('reports page rejects an invalid date range without crashing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('reports.index'))
        ->get(route('reports.index', ['from' => '2026-06-20', 'to' => '2026-06-01']))
        ->assertRedirect(route('reports.index'))
        ->assertSessionHasErrors('to');
});
