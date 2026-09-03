<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Tenant;
use App\Services\Demo\PurgeDemoTenant;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the demo tenants (SLO-183, docs/20 §3.2).
 *
 * The orchestrator owns the two things a persona must not decide for itself:
 * whether it is allowed to write over what is already there, and what happens
 * when a slug it wants belongs to somebody real.
 */
final class DemoSeeder
{
    /**
     * Every persona, in build order.
     *
     * A literal list rather than a directory scan: adding a persona should be a
     * reviewed line in a diff, not a file appearing. The remaining sales personas
     * (docs/20 §2.3) joins this list in SLO-187.
     *
     * @var list<class-string<DemoPersona>>
     */
    public const PERSONAS = [
        SmokeDemoPersona::class,
        PsychologistDemoPersona::class,
        SalonDemoPersona::class,
        VenueDemoPersona::class,
    ];

    public function __construct(private readonly PurgeDemoTenant $purge) {}

    /**
     * Seed one persona or all of them.
     *
     * @param  string|null  $slug  only this persona; null = every one
     * @param  bool  $fresh  drop and rebuild instead of leaving an existing demo tenant alone
     * @return list<string> the slugs actually built
     *
     * @throws RuntimeException when a slug belongs to a tenant that is not a demo one
     */
    public function run(?string $slug = null, bool $fresh = false): array
    {
        $seeded = [];

        foreach ($this->personas($slug) as $persona) {
            $existing = $this->existing($persona->slug());

            if ($existing !== null) {
                // ⚠️ The guardrail that matters most in this class. A real
                // tenant that happens to own this slug must stop the command
                // dead — never be rebuilt into a demo. docs/20 §3.1 states it as
                // a hard rule, and the failure it prevents is the irreversible
                // kind: a paying customer's data replaced by fixtures.
                if (! $existing->is_demo) {
                    throw new RuntimeException(
                        "Refusing to seed [{$persona->slug()}]: a non-demo tenant already owns that slug (docs/20 §3.1)."
                    );
                }

                // Idempotent without --fresh (docs/20 §3.2): an existing demo
                // tenant is left exactly as it is, so a re-run during a demo
                // does not pull the data out from under whoever is presenting.
                if (! $fresh) {
                    continue;
                }

                ($this->purge)($existing);
            }

            // One transaction per persona: a persona that fails half-built
            // leaves nothing behind, and the ones already seeded stay.
            $this->withoutBroadcasting(fn () => DB::transaction(fn () => $persona->seed()));

            $seeded[] = $persona->slug();
        }

        return $seeded;
    }

    /**
     * Every demo tenant currently in the database — what `demo:reset` rebuilds,
     * including a persona that has since been removed from {@see PERSONAS}.
     *
     * @return list<string>
     */
    public function existingDemoSlugs(): array
    {
        return Tenant::withTrashed()->demo()->orderBy('id')->pluck('slug')->all();
    }

    /**
     * Run `$work` with broadcasting switched off.
     *
     * A seed creates its bookings through the real Actions, which is the point
     * (docs/20 §3.3) — but those fire `BookingCreated`, and its listener
     * broadcasts to Reverb. Two consequences, both bad and neither obvious:
     *
     * - a few hundred realtime events fire at 03:00 for a calendar nobody has
     *   open, and
     * - if Reverb is down, the broadcast throws and takes the whole nightly
     *   reset with it. The demo would then be missing because the *websocket*
     *   was missing, which is not a dependency it should have.
     *
     * Only broadcasting is muted, deliberately. `Event::fake()` would have been
     * the reflex and would also have silenced the commission ledger listener —
     * whose rows are exactly what the demo dashboards are built to show.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function withoutBroadcasting(Closure $work): mixed
    {
        $previous = config('broadcasting.default');

        config(['broadcasting.default' => 'null']);

        try {
            return $work();
        } finally {
            config(['broadcasting.default' => $previous]);
        }
    }

    /**
     * @return list<DemoPersona>
     *
     * @throws RuntimeException when `$slug` names no persona
     */
    private function personas(?string $slug): array
    {
        /** @var list<DemoPersona> $all */
        $all = array_map(static fn (string $class): DemoPersona => new $class, self::PERSONAS);

        if ($slug === null) {
            return $all;
        }

        $matching = array_values(array_filter($all, static fn (DemoPersona $p): bool => $p->slug() === $slug));

        if ($matching === []) {
            $known = implode(', ', array_map(static fn (DemoPersona $p): string => $p->slug(), $all));

            throw new RuntimeException("Unknown demo persona [{$slug}]. Known personas: {$known}.");
        }

        return $matching;
    }

    /**
     * The tenant on this slug, if any.
     *
     * `withTrashed()`: archiving soft-deletes, and a soft-deleted row still
     * holds the slug. Missing it would turn a rebuild into a unique-constraint
     * violation that reads like a bug in the seeder rather than a leftover.
     */
    private function existing(string $slug): ?Tenant
    {
        return Tenant::withTrashed()->where('slug', $slug)->first();
    }
}
