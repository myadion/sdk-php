<?php

declare(strict_types=1);

namespace MyAdion\Exception;

/**
 * The token is valid but may not do this (403): a scope is missing, the
 * project is outside the application, or the owner lost the right.
 */
class PermissionError extends MyAdionException {}
