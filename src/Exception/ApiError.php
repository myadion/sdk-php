<?php

declare(strict_types=1);

namespace MyAdion\Exception;

/**
 * The API failed (5xx) or answered something the library cannot read.
 */
class ApiError extends MyAdionException {}
