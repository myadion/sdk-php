<?php

declare(strict_types=1);

namespace MyAdion\Exception;

/**
 * The credentials were refused (401): wrong client id or secret, revoked
 * application, expired or revoked token.
 */
class AuthenticationError extends MyAdionException {}
