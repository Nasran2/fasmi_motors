<?php

use App\Models\User;

test('erp sidebar renders searchable accordion categories', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('id="sidebar-menu-search"', false)
        ->assertSee('data-menu-group', false)
        ->assertSeeText('Customers & Bikes')
        ->assertSeeText('Sales / Billing')
        ->assertSeeText('User Management');
});

test('every sidebar submenu has a valid dedicated url', function () {
    $directLinks = config('workshop-navigation.direct');

    foreach (config('workshop-navigation.groups') as $group) {
        foreach ($group['children'] as $child) {
            $key = $group['slug'].'.'.$child['slug'];
            $direct = $directLinks[$key] ?? null;
            $url = $direct
                ? route($direct['route'], $direct['parameters'] ?? [])
                : route('workspace.page', ['section' => $group['slug'], 'page' => $child['slug']]);

            expect($url)->toBeString()->not->toBeEmpty();
        }
    }
});

test('dedicated workspace pages load and keep their sidebar category active', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspace.page', ['section' => 'sms-center', 'page' => 'sms-templates']))
        ->assertOk()
        ->assertSeeText('SMS Templates')
        ->assertSee('data-title="sms center"', false)
        ->assertSee('data-open="true"', false);
});
