<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A backup or restore turned down before it changed anything.
 *
 * The message is shown to the Super Admin as it stands, so it is kept to one
 * short sentence. Anything else thrown during a backup or restore is a fault,
 * reported and answered with a generic message instead.
 */
class BackupRefused extends RuntimeException {}
