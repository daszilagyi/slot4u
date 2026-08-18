<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * A backup step that did not produce what it promised (SLO-154).
 *
 * Every failure in this subsystem is fatal to the run by design. There is no
 * "partial backup" worth keeping: an archive that uploaded but cannot be
 * restored is worse than a missing one, because it stops anyone from looking.
 */
final class BackupFailed extends RuntimeException {}
