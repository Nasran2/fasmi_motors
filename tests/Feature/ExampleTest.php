<?php

test('the site root opens the login page', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/login');
});
