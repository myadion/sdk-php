<?php

declare(strict_types=1);

namespace MyAdion\Exception;

/**
 * The API could not be reached: DNS, TLS, timeout, connection reset.
 */
class ApiConnectionError extends MyAdionException {}
