<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Takes the second factor off an account from the shell (SLO-149).
 *
 * The way back for a superadmin who lost their authenticator AND their recovery
 * codes. Without it, making two-factor mandatory for that account would mean a
 * lost phone locks the platform's only administrator out of every tenant —
 * which is not a security posture, it is a single point of failure with a
 * password on it.
 *
 * ⚠️ It is not a new way in. Running it needs shell access to the production
 * host, and anyone with that could edit the `users` row directly. What this adds
 * over `UPDATE users SET ...` is that it is documented (docs/03), it cannot
 * fat-finger the wrong column, and it leaves an audit entry — a hand-written
 * UPDATE leaves nothing at all.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'two-factor:reset {email : The account to unlock}';

    protected $description = "Remove an account's two-factor secret and recovery codes (recovery path)";

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->components->error('No account with that email address.');

            return self::FAILURE;
        }

        if ($user->two_factor_secret === null && $user->two_factor_confirmed_at === null) {
            $this->components->info('That account has no second factor set up — nothing to reset.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Remove two-factor authentication from {$user->email}?", false)) {
            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        // Recorded, because the whole point of the second factor is that
        // removing it is an event somebody should be able to find later. The
        // actor is null: a console run has no signed-in user, and inventing one
        // would be worse than saying so.
        $audit->record(
            action: AuditAction::TwoFactorReset,
            auditable: $user,
            oldValues: ['two_factor_confirmed' => true],
            newValues: ['two_factor_confirmed' => false, 'via' => 'console'],
            tenantId: $user->tenant_id,
        );

        $this->components->info("Two-factor removed from {$user->email}. They can set it up again at /security.");

        return self::SUCCESS;
    }
}
