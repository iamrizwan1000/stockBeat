<?php

namespace App\Http\Controllers;

use App\Actions\Connections\ComputeStoreConnectionFingerprintAction;
use App\Contracts\OAuthChannelAdapter;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Support\Concurrency\IdempotencyGuard;
use App\Support\Connections\ChannelAdapterManager;
use App\Support\Connections\OAuthState;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public OAuth callback ingress (Plan §7) — Shopify/eBay/Etsy all redirect
 * the merchant's browser back here after they approve the connection on
 * the platform's own site. Deliberately outside `/api/v1` (no Sanctum
 * auth possible — this is a browser redirect, not an API call from our
 * own mobile app) — the signed `state` param is what proves this request
 * actually originated from our own `StartOAuthConnectionAction` call.
 *
 * Renders a plain result page rather than a deep link back into the app —
 * the React Native app doesn't exist yet (§15.1), so there's no scheme to
 * redirect to. Replace with a deep link once it does.
 */
class OAuthCallbackController extends Controller
{
    public function shopify(Request $request, ChannelAdapterManager $adapters, ComputeStoreConnectionFingerprintAction $fingerprint): View
    {
        return $this->complete('shopify', $request, $adapters, $fingerprint);
    }

    public function ebay(Request $request, ChannelAdapterManager $adapters, ComputeStoreConnectionFingerprintAction $fingerprint): View
    {
        return $this->complete('ebay', $request, $adapters, $fingerprint);
    }

    public function etsy(Request $request, ChannelAdapterManager $adapters, ComputeStoreConnectionFingerprintAction $fingerprint): View
    {
        return $this->complete('etsy', $request, $adapters, $fingerprint);
    }

    public function amazon(Request $request, ChannelAdapterManager $adapters, ComputeStoreConnectionFingerprintAction $fingerprint): View
    {
        return $this->complete('amazon', $request, $adapters, $fingerprint);
    }

    public function tiktok(Request $request, ChannelAdapterManager $adapters, ComputeStoreConnectionFingerprintAction $fingerprint): View
    {
        return $this->complete('tiktok', $request, $adapters, $fingerprint);
    }

    /**
     * Guarded against duplicate connections two ways: `OAuthState` has no
     * single-use enforcement at all (the same `state`/nonce can be decoded
     * and replayed indefinitely) — the lock below, keyed to the exact
     * nonce (one per `/start` call), closes a literal replay of the same
     * callback URL (a client retry, a redirect firing twice) without
     * blocking a *different* `/start` attempt (a different nonce) for a
     * different store moments later. For Shopify specifically (the one
     * OAuth platform with a real, stable store identity —
     * `ComputeStoreConnectionFingerprintAction`), an existing-connection
     * check by fingerprint also catches a genuine reconnect-to-the-same-
     * store retry minutes/hours later (past the lock's window, a fresh
     * nonce), not just a same-link replay; eBay/Etsy/Amazon/TikTok have no
     * such identity available without extra OAuth scope (a documented,
     * pre-existing scope cut), so a reconnect to the same store on those
     * four platforms via a genuinely new `/start` call can still duplicate.
     */
    private function complete(string $platform, Request $request, ChannelAdapterManager $adapters, ComputeStoreConnectionFingerprintAction $fingerprintAction): View
    {
        $rawState = (string) $request->query('state', '');
        $state = OAuthState::decode($rawState);

        if ($state === null || $state->platform !== $platform) {
            return $this->result($platform, false, 'This connection link is invalid or expired. Please try connecting again from the app.');
        }

        $team = Team::query()->find($state->teamId);

        if ($team === null) {
            return $this->result($platform, false, 'We could not find your account. Please try connecting again from the app.');
        }

        /** @var OAuthChannelAdapter $adapter */
        $adapter = $adapters->driver($platform);

        try {
            $connection = IdempotencyGuard::once("oauth-callback:{$state->nonce}", 120, function () use ($platform, $team, $state, $adapter, $fingerprintAction, $request) {
                $fingerprint = $fingerprintAction->handle($platform, $state->credentials);

                if ($fingerprint !== null) {
                    $existing = StoreConnection::query()
                        ->where('team_id', $team->id)
                        ->where('fingerprint', $fingerprint)
                        ->where('status', '!=', StoreConnection::STATUS_DISCONNECTED)
                        ->first();

                    // Short-circuits (skips the OAuth token exchange and
                    // webhook re-registration entirely) only for a
                    // genuinely healthy existing connection — a merchant
                    // retrying `/start` for a store they're already
                    // connected to shouldn't burn an extra token exchange.
                    // A `needs_reauth` connection must NOT short-circuit
                    // here: that's exactly what a "Reconnect" tap is for,
                    // and returning the stale row as-is would silently
                    // no-op it — same status, same dead token, forever
                    // (the actual bug this comment used to not mention).
                    // `completeConnection()` itself re-finds this same row
                    // by fingerprint and updates it in place rather than
                    // creating a duplicate.
                    if ($existing !== null && $existing->status === StoreConnection::STATUS_ACTIVE) {
                        return $existing;
                    }
                }

                $connection = $adapter->completeConnection($team, $state->name, $state->credentials, $state->nonce, $request);

                $fingerprint ??= $fingerprintAction->handle($platform, $connection->credentials ?? []);

                if ($fingerprint !== null) {
                    $connection->update(['fingerprint' => $fingerprint]);
                }

                return $connection;
            });
        } catch (\Throwable $e) {
            report($e);

            return $this->result($platform, false, 'We could not complete the connection. Please try again from the app.');
        }

        if ($connection === null) {
            return $this->result($platform, false, 'This connection link has already been used. Please try connecting again from the app.');
        }

        return $this->result($platform, true, "{$connection->name} is connected. You can return to the app now.");
    }

    /**
     * Best-effort auto-return to the app via a custom URL scheme (mobile
     * docs §connections gap — there was no app to deep-link to when this
     * controller was first written). If the scheme isn't registered on the
     * client, the redirect silently no-ops and the merchant just sees this
     * page's own confirmation text and switches back manually, same as
     * before — so this is purely additive, not a replacement for the
     * existing `GET /connections` poll-and-diff fallback.
     */
    private function result(string $platform, bool $success, string $message): View
    {
        $deepLink = 'stockbeat://oauth-callback?'.http_build_query([
            'platform' => $platform,
            'success' => $success ? 'true' : 'false',
            'message' => $message,
        ]);

        return view('connections.oauth-result', compact('success', 'message', 'deepLink'));
    }
}
