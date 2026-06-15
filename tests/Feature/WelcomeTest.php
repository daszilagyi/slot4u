<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the welcome inertia page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('translations')
            ->where('locale', 'hu')
        );
});
