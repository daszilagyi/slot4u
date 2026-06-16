<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the welcome inertia page on the central domain', function () {
    $central = config('tenancy.central_domain');

    $this->get('http://'.$central.'/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('translations')
            ->where('locale', 'hu')
        );
});
