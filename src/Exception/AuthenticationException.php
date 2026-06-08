<?php

declare(strict_types=1);

namespace ElyonPay\Exception;

/** Thrown when login fails or the JWT is rejected (HTTP 401). */
class AuthenticationException extends ElyonPayException
{
}
