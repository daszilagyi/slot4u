<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A liveness mark for one unsupervised moving part (SLO-153).
 *
 * @property string $name
 * @property Carbon $beat_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Heartbeat extends Model
{
    /** The queue worker's cron run (SLO-125: `queue:work --stop-when-empty`). */
    public const QUEUE = 'queue';

    /** The scheduler's cron run, stamped by the scheduled health check itself. */
    public const SCHEDULER = 'scheduler';

    /** The last *successful* offsite backup (SLO-154) — stamped after the upload. */
    public const BACKUP = 'backup';

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'beat_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['beat_at' => 'datetime'];
    }
}
