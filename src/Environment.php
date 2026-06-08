<?php

declare(strict_types=1);

namespace ElyonPay;

/**
 * ElyonPay environments and their base URLs.
 *
 * NOTE: the official docs are slightly inconsistent about the sandbox/prod
 * hostnames. These values follow the "Environments" table of the docs:
 *   - Sandbox    : https://api.elyonpay.net/api
 *   - Production : https://api.elyonpay.org/api
 * If ElyonPay harmonises the hostnames, this is the single place to change.
 */
final class Environment
{
    public const SANDBOX    = 'sandbox';
    public const PRODUCTION = 'production';

    private const BASE_URLS = [
        self::SANDBOX    => 'https://api.elyonpay.net/api',
        self::PRODUCTION => 'https://api.elyonpay.org/api',
    ];

    public static function baseUrl(string $environment): string
    {
        if (!isset(self::BASE_URLS[$environment])) {
            throw new \InvalidArgumentException(
                sprintf('Unknown environment "%s". Use Environment::SANDBOX or Environment::PRODUCTION.', $environment)
            );
        }

        return self::BASE_URLS[$environment];
    }

    public static function isValid(string $environment): bool
    {
        return isset(self::BASE_URLS[$environment]);
    }
}
