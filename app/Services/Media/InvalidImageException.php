<?php

namespace App\Services\Media;

use InvalidArgumentException;

/**
 * An image was rejected. The message is written for staff and is safe to show.
 */
class InvalidImageException extends InvalidArgumentException {}
