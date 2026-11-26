<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

use Runio\ScriptUi\Http\Request;

/**
 * Decides whether a request comes from the machine the code runs on.
 * Tools without a login check only answer to those requests.
 *
 * @internal
 */
final class Guard
{
    /**
     * A request that passed through a proxy or a tunnel carries one of these.
     * Then the address PHP sees is the proxy's, not the visitor's, so it proves nothing.
     */
    private const PROXY_HEADERS = [
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'forwarded',
        'x-real-ip',
        'x-client-ip',
        'true-client-ip',
        'cf-connecting-ip',
        'via',
    ];

    public static function isLocal(Request $request): bool
    {
        if (!self::isLoopback($request->remoteAddr)) {
            return false;
        }

        foreach (self::PROXY_HEADERS as $header) {
            if ($request->header($header) !== null) {
                return false;
            }
        }

        if (self::looksPassedOn($request)) {
            return false;
        }

        // A web page on another site can point its own domain name at 127.0.0.1 and
        // make the browser call us (DNS rebinding). The browser then still sends
        // that foreign name as the host, so only local host names are accepted.
        $host = $request->header('host');

        return $host !== null && self::isLocalHostName($host);
    }

    /**
     * A proxy can pass a request on without adding any header. nginx does that
     * with a bare "proxy_pass", and it even writes our own address into the host
     * header. Such a proxy still gives itself away: it closes the connection after
     * every request, and nginx before 1.29.7 speaks HTTP/1.0. Browsers do neither.
     *
     * This is a net under the other checks, not a proof. A proxy that hides
     * completely cannot be seen from here, which is why anything reachable from
     * outside needs a login check.
     */
    private static function looksPassedOn(Request $request): bool
    {
        if (preg_match('~^HTTP/(?:0\.9|1\.0)$~i', trim($request->protocol)) === 1) {
            return true;
        }

        $connection = $request->header('connection');

        return $connection !== null && preg_match('/(?:^|,)\s*close\s*(?:,|$)/i', $connection) === 1;
    }

    private static function isLoopback(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return $packed[0] === "\x7f";
        }

        $mappedIpv4 = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

        return $packed === inet_pton('::1')
            || (str_starts_with($packed, $mappedIpv4) && $packed[12] === "\x7f");
    }

    private static function isLocalHostName(string $host): bool
    {
        if (preg_match('/^(\[::1\]|[a-z0-9.-]+)(?::\d{1,5})?$/D', strtolower($host), $match) !== 1) {
            return false;
        }

        $name = $match[1];

        return $name === 'localhost'
            || $name === '127.0.0.1'
            || $name === '[::1]'
            || str_ends_with($name, '.localhost');
    }
}
