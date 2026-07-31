<?php

namespace App\Jobs\Concerns;

use Illuminate\Http\Client\Response;

/**
 * Shopify's REST Admin API uses cursor pagination via a `Link` response
 * header (RFC 5988) — `page_info` is opaque and only valid combined with
 * the rest of that same URL, unlike WooCommerce's simple page-number
 * scheme, so the full `rel="next"` URL is followed as-is rather than
 * reconstructing query params by hand. Shared by every Shopify poll job
 * that can return more than one page (orders, products).
 */
trait PaginatesShopifyLinkHeader
{
    private function nextPageUrl(Response $response): ?string
    {
        $link = $response->header('Link');

        if (! is_string($link) || $link === '') {
            return null;
        }

        foreach (explode(',', $link) as $part) {
            if (str_contains($part, 'rel="next"') && preg_match('/<([^>]+)>/', $part, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
