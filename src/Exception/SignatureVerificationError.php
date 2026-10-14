<?php

declare(strict_types=1);

namespace MyAdion\Exception;

/**
 * A webhook signature is missing, malformed, wrong or too old.
 */
class SignatureVerificationError extends MyAdionException {}
