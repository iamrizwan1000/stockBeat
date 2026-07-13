<?php

test('the home route redirects to the admin panel', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/admin');
});
