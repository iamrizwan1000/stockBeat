<?php

namespace App\Support\Connections\Adapters\Amazon;

/**
 * Minimal, hand-rolled AWS Signature Version 4 signer (Plan §7.5: every
 * SP-API data-plane request must carry SigV4 auth, and the STS AssumeRole
 * call needed to obtain temporary credentials is itself a SigV4-signed
 * request). `aws/aws-sdk-php` is not a dependency of this project, and no
 * other adapter needs AWS auth — every other adapter (eBay/Etsy/Woo) talks
 * to its platform with a plain `Http::` facade call and no vendor SDK (the
 * Twilio SMS integration made the same call explicitly, "no SDK dependency,
 * matching the WooCommerce adapter's convention") — so a small
 * purpose-built signer here matches that existing convention rather than
 * pulling in the full AWS SDK for one auth scheme.
 *
 * Deliberately scoped to exactly what SP-API/STS need: signing a single,
 * fully-buffered request (JSON or form-encoded body, or none) against one
 * host. It does not attempt chunked/streaming payload signing or presigned
 * URLs — SP-API doesn't ask for either.
 *
 * Only three headers are ever signed (`host`, `x-amz-content-sha256`,
 * `x-amz-date`, plus `x-amz-security-token` when present) — this is a
 * deliberately minimal but fully valid canonical request per AWS's own
 * spec (signing more headers only tightens the signature, it's never
 * required for correctness).
 *
 * Reference: https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 */
final readonly class AwsSigV4Signer
{
    public function __construct(
        private string $accessKeyId,
        private string $secretAccessKey,
        private ?string $sessionToken,
        private string $region,
        private string $service,
    ) {}

    /**
     * Returns the headers to merge onto the outgoing request. `$path` must
     * be the raw (unencoded) request path; `$query` is the full set of
     * query string parameters that will actually be sent — the caller must
     * send exactly these, since the signature covers them.
     *
     * @param  array<string, string>  $query
     * @return array<string, string>
     */
    public function sign(string $method, string $host, string $path, array $query, string $body): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $canonicalHeadersMap = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];

        if ($this->sessionToken !== null) {
            $canonicalHeadersMap['x-amz-security-token'] = $this->sessionToken;
        }

        ksort($canonicalHeadersMap);

        $canonicalHeaders = '';
        foreach ($canonicalHeadersMap as $key => $value) {
            $canonicalHeaders .= "{$key}:{$value}\n";
        }

        $signedHeaders = implode(';', array_keys($canonicalHeadersMap));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalUri($path),
            $this->canonicalQueryString($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$this->region}/{$this->service}/aws4_request";

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        $headers = [
            'Authorization' => sprintf(
                'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $this->accessKeyId,
                $credentialScope,
                $signedHeaders,
                $signature,
            ),
            'X-Amz-Date' => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
        ];

        if ($this->sessionToken !== null) {
            $headers['X-Amz-Security-Token'] = $this->sessionToken;
        }

        return $headers;
    }

    private function canonicalUri(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        // URI-encode every path segment individually (not the separating
        // "/" itself) — the AWS spec's own required deviation from a
        // straight rawurlencode() of the whole path.
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $path),
        ));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function canonicalQueryString(array $query): string
    {
        if ($query === []) {
            return '';
        }

        $encoded = [];

        foreach ($query as $key => $value) {
            $encoded[rawurlencode((string) $key)] = rawurlencode((string) $value);
        }

        ksort($encoded);

        $pairs = [];
        foreach ($encoded as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        return implode('&', $pairs);
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, "AWS4{$this->secretAccessKey}", true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
