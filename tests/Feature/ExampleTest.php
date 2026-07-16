<?php

test('the home route serves the public marketing page', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('welcome'));
});
