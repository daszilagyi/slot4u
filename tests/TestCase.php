<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }
}
