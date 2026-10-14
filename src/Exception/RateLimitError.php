<?php

declare(strict_types=1);

namespace MyAdion\Exception;

/**
 * Too many calls (429). The library already retried; slow down.
 */
class RateLimitError extends MyAdionException {}
