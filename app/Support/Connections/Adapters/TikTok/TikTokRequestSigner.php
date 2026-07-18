<?php

namespace App\Support\Connections\Adapters\TikTok;

/**
 * TikTok Shop Partner API request signing (Plan §7.6). Every authenticated
 * call — not just the OAuth token exchange — must carry a `sign` query
 * parameter computed from the request itself, on top of (not instead of)
 * the per-connection `x-tts-access-token` header. This is the real,
 * documented Partner API v2 algorithm: sort every query parameter
 * (excluding `sign` and `access_token`, which are never part of the signed
 * material) by key, concatenate as `key` immediately followed by `value`
 * with no separators, prefix the result with the request path, append the
 * raw request body for non-multipart requests, wrap the whole string with
 * the app secret on both ends, then HMAC-SHA256 (keyed with the app secret
 * again) and hex-encode. Isolated in its own class for the same reason
 * `AwsSigV4Signer` is — a platform-specific signing scheme that doesn't
 * belong inlined into the adapter, and is independently unit-testable.
 *
 * Verify at build time: TikTok Shop's exact canonicalization of nested/array
 * query values (this flattens scalar params only, which covers every call
 * `TikTokAdapter` makes) and whether the timestamp a given API version
 * expects is seconds vs. milliseconds — this uses seconds per the current
 * Partner API v2 docs.
 */
class TikTokRequestSigner
{
    public function __construct(
        private readonly string $appKey,
        private readonly string $appSecret,
    ) {}

    /**
     * @param  array<string, string>  $query  Every query param the request will carry, excluding `sign`/`timestamp`/`app_key` (added here).
     * @return array{app_key: string, timestamp: int, sign: string}
     */
    public function sign(string $path, array $query, string $body = ''): array
    {
        $timestamp = (int) now()->timestamp;

        $params = $query;
        $params['app_key'] = $this->appKey;
        $params['timestamp'] = (string) $timestamp;
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $concatenated = $path;

        foreach ($params as $key => $value) {
            $concatenated .= $key.$value;
        }

        $concatenated .= $body;
        $wrapped = $this->appSecret.$concatenated.$this->appSecret;

        return [
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
            'sign' => hash_hmac('sha256', $wrapped, $this->appSecret),
        ];
    }
}
