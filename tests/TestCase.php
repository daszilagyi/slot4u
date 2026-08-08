<?php

namespace Tests;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Validation\Rules\Password;
use Tests\Fixtures\FakeUncompromisedVerifier;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render the Inertia root view (app.blade.php) which pulls in
        // the Vite client via @vite. CI runs the PHP suite without a frontend build,
        // so the Vite manifest is absent and every page render would 500. Faking the
        // manifest keeps HTTP/Inertia assertions meaningful without a build step.
        $this->withoutVite();

        // The password policy (SLO-145) includes a breach check, which is an HTTP
        // call to haveibeenpwned. The suite must not depend on the network, so the
        // verifier is faked to "not breached" by default; the test that proves the
        // rule bites swaps in one that answers the other way.
        $this->app->instance(
            UncompromisedVerifier::class,
            new FakeUncompromisedVerifier(compromised: false),
        );

        // Password::defaults() is a static on the rule class, so it survives between
        // tests — re-arm it every time rather than trusting whatever ran before.
        Password::defaults(fn () => Password::min(12)->uncompromised());
    }
}
