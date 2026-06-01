<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * Authentication roles. The backed value is the canonical string carried in
 * the JWT `role` claim, returned by the login endpoint, and checked by the
 * REST permission callbacks — single source of truth so the wire value can't
 * drift (e.g. 'member' vs 'ROLE_MEMBER').
 */
enum Role: string
{
    case Member = 'ROLE_MEMBER';
    case Admin  = 'ROLE_ADMIN';
}
