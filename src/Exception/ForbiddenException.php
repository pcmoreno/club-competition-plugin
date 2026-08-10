<?php

declare(strict_types=1);

namespace SCS\Exception;

// Signed in, but not allowed. Distinct from UnauthorizedException because the
// frontend treats a 401 as an expired session and signs the user out.
class ForbiddenException extends \RuntimeException
{
}
