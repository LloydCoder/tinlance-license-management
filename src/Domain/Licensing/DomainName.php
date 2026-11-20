<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use InvalidArgumentException;
use RuntimeException;

final class DomainName
{
    public static function normalize(string $input): string
    {
        if ($input === '' || trim($input) !== $input) {
            throw new InvalidArgumentException('A domain name is required without surrounding whitespace.');
        }

        if (strpbrk($input, '/:?#@[]\\\\%*') !== false) {
            throw new InvalidArgumentException('A domain name must not contain a scheme, port, path, query, credentials, wildcard or URL syntax.');
        }

        $domain = str_ends_with($input, '.') ? substr($input, 0, -1) : $input;

        if ($domain === '' || str_ends_with($domain, '.')) {
            throw new InvalidArgumentException('The domain name has an invalid terminal dot.');
        }

        if (! function_exists('idn_to_ascii')) {
            throw new RuntimeException('The PHP intl extension is required for safe IDNA domain normalization.');
        }

        $info = [];
        $ascii = idn_to_ascii(
            $domain,
            IDNA_USE_STD3_RULES | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46,
            $info,
        );

        if ($ascii === false || ($info['errors'] ?? 0) !== 0) {
            throw new InvalidArgumentException('The domain name is not valid under IDNA processing.');
        }

        $ascii = strtolower($ascii);

        if (strlen($ascii) > 253
            || filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($ascii, FILTER_VALIDATE_IP) !== false
        ) {
            throw new InvalidArgumentException('The domain name is not a valid DNS hostname.');
        }

        return $ascii;
    }
}
