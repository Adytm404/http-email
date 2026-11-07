<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads a caller-supplied URL without letting it reach private infrastructure.
 *
 * - only http/https, no credentials in the URL, only ports 80/443
 * - the host is resolved here and every address must be public (no loopback, private, link-local,
 *   metadata, carrier-grade NAT or reserved ranges)
 * - the connection is pinned to the address that was checked (curl) so DNS cannot change in between
 * - redirects are followed by hand, a few at most, and each hop is checked again
 * - the body is read in chunks and aborted as soon as it passes the size limit
 * - errors never include connection details, so the endpoint is not a port or host scanner
 *
 * MAILROUTE_ALLOW_PRIVATE_ATTACHMENT_URLS=true switches the address and port checks off.
 */
class SafeFetcher
{
    private const REDIRECTS = [301, 302, 303, 307, 308];

    /** @param  Closure(string): list<string>|null  $resolver  Returns the IP addresses of a host. */
    public function __construct(private ?Closure $resolver = null) {}

    /** @return array{body: string, content_type: ?string} */
    public function fetch(string $url, int $maxBytes, int $timeout = 15, int $maxRedirects = 3): array
    {
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            [$host, $port, $pin] = $this->guard($url);

            $options = ['allow_redirects' => false, 'stream' => true];
            if ($pin !== null && defined('CURLOPT_RESOLVE')) {
                $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pin}"]];
            }

            try {
                $response = Http::timeout($timeout)->withOptions($options)->get($url);
            } catch (Throwable) {
                throw new SafeFetchException('Could not download attachment.');
            }

            if (in_array($response->status(), self::REDIRECTS, true)) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new SafeFetchException('Could not download attachment.');
                }
                $url = $this->absolute($url, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new SafeFetchException("Could not download attachment (HTTP {$response->status()}).");
            }

            $length = (int) $response->header('Content-Length');
            if ($length > $maxBytes) {
                throw new SafeFetchException('Attachments exceed the 40MB limit.');
            }

            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            try {
                while (! $stream->eof()) {
                    $body .= $stream->read(65536);
                    if (strlen($body) > $maxBytes) {
                        throw new SafeFetchException('Attachments exceed the 40MB limit.');
                    }
                }
            } catch (SafeFetchException $e) {
                throw $e;
            } catch (Throwable) {
                throw new SafeFetchException('Could not download attachment.');
            }

            return [
                'body' => $body,
                'content_type' => strtok((string) $response->header('Content-Type'), ';') ?: null,
            ];
        }

        throw new SafeFetchException('The attachment URL redirects too many times.');
    }

    /** @return array{0: string, 1: int, 2: ?string} host, port and the address to connect to */
    private function guard(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new SafeFetchException('The attachment URL is not allowed.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $allowPrivate = (bool) config('mailroute.allow_private_attachment_urls');

        if (! $allowPrivate && ! in_array($port, [80, 443], true)) {
            throw new SafeFetchException('The attachment URL is not allowed: use port 80 or 443.');
        }

        $isLiteral = (bool) filter_var($host, FILTER_VALIDATE_IP);
        $addresses = $isLiteral ? [$host] : $this->resolve($host);
        if ($addresses === []) {
            throw new SafeFetchException('Could not resolve the attachment host.');
        }

        if (! $allowPrivate) {
            foreach ($addresses as $ip) {
                if (! self::isPublicAddress($ip)) {
                    throw new SafeFetchException('The attachment URL is not allowed: it points to a private or reserved address.');
                }
            }
        }

        $ip = $addresses[0];
        $pin = $isLiteral ? null : (str_contains($ip, ':') ? "[{$ip}]" : $ip);

        return [$host, $port, $pin];
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if ($this->resolver) {
            return array_values(($this->resolver)($host));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $p = parse_url($base);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($location, '//')) {
            return $p['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $p['path'] ?? '/';
        $dir = rtrim(substr($path, 0, strrpos($path, '/') + 1), '/');

        return $origin.$dir.'/'.$location;
    }

    public static function isPublicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:127.0.0.1) is judged by the IPv4 address inside it.
        $packed = inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
            $packed = inet_pton($ip);
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '192.88.99.0/24'] as $cidr) {
                [$net, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if (($n & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        } else {
            // Unique-local (fc00::/7) and link-local (fe80::/10) are not always covered by the PHP flags.
            $first = ord($packed[0]);
            if (($first & 0xFE) === 0xFC || ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80)) {
                return false;
            }
        }

        return true;
    }
}
