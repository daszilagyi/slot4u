<?php

/*
|--------------------------------------------------------------------------
| What survives `composer install --no-dev` (SLO-217)
|--------------------------------------------------------------------------
|
| The deploy installs without dev dependencies (deploy/deploy.sh), but the
| `App\` and `Database\` namespaces are in composer.json's `autoload` — NOT
| `autoload-dev` — so every class under them ships to production. A prod class
| that imports a `require-dev` package therefore looks perfectly fine here and
| fatals there.
|
| That is not hypothetical. It shipped: `DemoDataFactory` imported `Faker`,
| which was require-dev, so `demo:seed` — the documented last step of a release
| — died with "Class Faker\Factory not found" the first time anyone ran it on
| the server. Nothing local could have told us: dev and CI both install the dev
| set, and the failure needs the production dependency set to appear.
|
| So this test builds that set from the lock file rather than trusting a list
| somebody has to remember to update.
|
*/

/**
 * The PSR-4 namespaces that `composer install --no-dev` leaves out.
 *
 * Read from composer's own installed.json: `dev-package-names` is the authority
 * on what is dev-only, and each package declares its own namespaces. A hardcoded
 * list here would be a second source of truth, and would rot the first time
 * somebody adds a dev tool.
 *
 * @return array<string, string> namespace prefix => package that provides it
 */
function devOnlyNamespaces(): array
{
    $path = base_path('vendor/composer/installed.json');

    expect(file_exists($path))->toBeTrue('vendor/composer/installed.json is missing — run composer install');

    /** @var array{packages: list<array<string, mixed>>, dev-package-names: list<string>} $installed */
    $installed = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $devNames = array_flip($installed['dev-package-names'] ?? []);

    // ⚠️ Namespaces WE own are not evidence of anything, even when a dev package
    // also declares them. `laravel/pint` is itself a Laravel application and
    // ships its own `App\` — without this, every `use App\...` in the codebase
    // reads as an import of Pint.
    /** @var array{autoload: array{psr-4: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    $ours = [];

    foreach (array_keys($composer['autoload']['psr-4'] ?? []) as $prefix) {
        $ours[trim((string) $prefix, '\\')] = true;
    }

    $namespaces = [];

    foreach ($installed['packages'] ?? [] as $package) {
        $name = (string) ($package['name'] ?? '');

        if (! isset($devNames[$name])) {
            continue;
        }

        foreach (array_keys($package['autoload']['psr-4'] ?? []) as $prefix) {
            $prefix = trim((string) $prefix, '\\');

            if ($prefix !== '' && ! isset($ours[$prefix])) {
                $namespaces[$prefix] = $name;
            }
        }
    }

    return $namespaces;
}

/**
 * Every PHP file that `composer install --no-dev` still ships.
 *
 * @return list<string>
 */
function productionSources(): array
{
    $files = [];

    // The two roots composer.json puts in `autoload` (not `autoload-dev`).
    foreach (['app', 'database'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('⚠️ ships no production class that imports a dev-only package', function () {
    $devNamespaces = devOnlyNamespaces();

    // Sanity: if this is empty the test proves nothing, and would go on proving
    // nothing silently for as long as it took somebody to notice.
    expect($devNamespaces)->not->toBeEmpty();

    $offences = [];

    foreach (productionSources() as $file) {
        $source = (string) file_get_contents($file);

        // Imports only — `use Foo\Bar;` at the start of a line. A namespace named
        // inside a comment or a string is not what fatals on the server.
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', $source, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $imported) {
            foreach ($devNamespaces as $prefix => $package) {
                if ($imported === $prefix || str_starts_with($imported, $prefix.'\\')) {
                    $offences[] = sprintf(
                        '%s imports %s (from %s, which is require-dev)',
                        str_replace(base_path().'/', '', $file),
                        $imported,
                        $package,
                    );
                }
            }
        }
    }

    expect($offences)->toBe([]);
});

it('keeps faker installable in production, because the demo seeder is a product feature', function () {
    // The specific regression (SLO-217). The demo tenants are not a test
    // fixture: the public landing's "try it live" section runs on them and the
    // nightly demo:reset rebuilds them on the server. A package the seeder
    // cannot start without belongs in `require`.
    /** @var array{require: array<string, string>, require-dev: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['require'])->toHaveKey('fakerphp/faker')
        ->and($composer['require-dev'] ?? [])->not->toHaveKey('fakerphp/faker');
});
