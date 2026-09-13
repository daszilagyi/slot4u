<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| deploy/link-docroot.sh — static files on the bridge docroot (SLO-233)
|--------------------------------------------------------------------------
|
| On production the apex domain is served from `~/public_html`, a bridge with
| its own index.php, not from the app's public/. The og-image, the favicon and
| the icons therefore answered 404 there for as long as they existed. This
| script links public/ into the docroot on every deploy.
|
| Run for real, against a throwaway pair of directories shaped like the server:
| the bridge files it must not touch, the hand-made `build` link it must accept
| as its own, and a hosting file it must leave alone.
|
*/

/**
 * @return array{root: string, public: string, docroot: string}
 */
function linkDocrootFixture(): array
{
    $root = sys_get_temp_dir().'/slot4u-docroot-'.bin2hex(random_bytes(6));
    $public = $root.'/slot4u/public';
    $docroot = $root.'/public_html';

    mkdir($public.'/img', 0o755, true);
    mkdir($public.'/build/assets', 0o755, true);
    mkdir($docroot, 0o755, true);

    file_put_contents($public.'/index.php', '<?php // the app front controller');
    file_put_contents($public.'/.htaccess', '# the app rewrite');
    file_put_contents($public.'/favicon.ico', '');
    file_put_contents($public.'/robots.txt', 'User-agent: *');
    file_put_contents($public.'/img/og-image.png', 'png');
    file_put_contents($public.'/hot', 'http://localhost:5173');

    // The bridge, as it is on the host.
    file_put_contents($docroot.'/index.php', '<?php // bridge: boots ../slot4u');
    file_put_contents($docroot.'/.htaccess', '# hosting rewrite with the /_ssr exception');
    symlink($public.'/build', $docroot.'/build');
    // A file the hosting account put there itself.
    file_put_contents($docroot.'/robots.txt', 'hosting robots');

    return ['root' => $root, 'public' => $public, 'docroot' => $docroot];
}

function linkDocrootRun(string $public, string $docroot): ProcessResult
{
    return Process::run(['bash', base_path('deploy/link-docroot.sh'), $public, $docroot]);
}

function linkDocrootCleanup(string $root): void
{
    Process::run(['rm', '-rf', $root]);
}

it('links the app static files into the bridge docroot', function () {
    ['root' => $root, 'public' => $public, 'docroot' => $docroot] = linkDocrootFixture();

    try {
        $result = linkDocrootRun($public, $docroot);

        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
            // The files that answered 404 on production.
            ->and(is_link($docroot.'/img'))->toBeTrue()
            ->and(file_get_contents($docroot.'/img/og-image.png'))->toBe('png')
            ->and(is_link($docroot.'/favicon.ico'))->toBeTrue()
            // The hand-made link is recognised as ours, not reported as foreign.
            ->and(is_link($docroot.'/build'))->toBeTrue()
            ->and($result->output())->not->toContain('build links somewhere else');
    } finally {
        linkDocrootCleanup($root);
    }
});

it('⚠️ never touches the bridge, the dev-only files, or a file the host put there', function () {
    ['root' => $root, 'public' => $public, 'docroot' => $docroot] = linkDocrootFixture();

    try {
        $result = linkDocrootRun($public, $docroot);

        expect($result->exitCode())->toBe(0)
            // Replacing the bridge's index.php with the app's would take the site down.
            ->and(is_link($docroot.'/index.php'))->toBeFalse()
            ->and(file_get_contents($docroot.'/index.php'))->toContain('bridge')
            ->and(file_get_contents($docroot.'/.htaccess'))->toContain('hosting rewrite')
            ->and(file_exists($docroot.'/hot'))->toBeFalse()
            ->and(file_get_contents($docroot.'/robots.txt'))->toBe('hosting robots')
            ->and($result->output())->toContain('robots.txt is a real file or directory, not ours — left alone');
    } finally {
        linkDocrootCleanup($root);
    }
});

it('is idempotent', function () {
    ['root' => $root, 'public' => $public, 'docroot' => $docroot] = linkDocrootFixture();

    try {
        linkDocrootRun($public, $docroot);
        $second = linkDocrootRun($public, $docroot);

        expect($second->exitCode())->toBe(0)
            ->and($second->output())->not->toContain('linked img')
            ->and(readlink($docroot.'/img'))->toBe(realpath($public).'/img');
    } finally {
        linkDocrootCleanup($root);
    }
});

it('removes a link to an entry deleted from public/, and leaves foreign links alone', function () {
    ['root' => $root, 'public' => $public, 'docroot' => $docroot] = linkDocrootFixture();

    try {
        mkdir($public.'/brand');
        linkDocrootRun($public, $docroot);
        expect(is_link($docroot.'/brand'))->toBeTrue();

        rmdir($public.'/brand');
        mkdir($root.'/elsewhere');
        symlink($root.'/elsewhere', $docroot.'/cgi-bin');

        $result = linkDocrootRun($public, $docroot);

        expect($result->exitCode())->toBe(0)
            ->and(is_link($docroot.'/brand'))->toBeFalse()
            ->and($result->output())->toContain('removed dead link brand')
            ->and(is_link($docroot.'/cgi-bin'))->toBeTrue();
    } finally {
        linkDocrootCleanup($root);
    }
});

it('leaves a same-named link that points somewhere else alone', function () {
    // Somebody pointed `img` elsewhere on purpose; replacing it would be this
    // script deciding something it cannot know.
    ['root' => $root, 'public' => $public, 'docroot' => $docroot] = linkDocrootFixture();

    try {
        mkdir($root.'/other-img');
        symlink($root.'/other-img', $docroot.'/img');

        $result = linkDocrootRun($public, $docroot);

        expect($result->exitCode())->toBe(0)
            ->and(readlink($docroot.'/img'))->toBe($root.'/other-img')
            ->and($result->output())->toContain('img links somewhere else');
    } finally {
        linkDocrootCleanup($root);
    }
});

it('does nothing where the docroot is the public directory itself', function () {
    ['root' => $root, 'public' => $public] = linkDocrootFixture();

    try {
        $result = linkDocrootRun($public, $public);

        expect($result->exitCode())->toBe(0)
            ->and($result->output())->toContain('the docroot is the app\'s public directory')
            ->and(is_link($public.'/img'))->toBeFalse();
    } finally {
        linkDocrootCleanup($root);
    }
});

it('is wired into the deploy, after the checkout and without being able to keep the site down', function () {
    $deploy = (string) file_get_contents(base_path('deploy/deploy.sh'));

    $checkout = strpos($deploy, 'git checkout --detach');
    $link = strpos($deploy, 'deploy/link-docroot.sh');
    $up = strpos($deploy, 'artisan up');

    expect($link)->not->toBeFalse()
        ->and($link)->toBeGreaterThan($checkout)
        ->and($link)->toBeLessThan($up)
        // A failure here must not abort `set -e` while maintenance mode is on.
        ->and($deploy)->toMatch('#link-docroot\.sh"[^\n]*\\\\\n\s*\|\| echo#');
});
