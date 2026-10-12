<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An email change link that can no longer be used; the message is safe to
 * show to whoever followed it.
 */
class EmailChangeFailed extends RuntimeException {}
