<?php

namespace App\Http\Controllers;

use App\Actions\Connections\ComputeStoreConnectionFingerprintAction;
use App\Contracts\OAuthChannelAdapter;
use App\Models\Team;
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

    private function complete(string $platform, Request $request, ChannelAdapterManager $adapters, ComputeStoreConnectionFingerprintAction $fingerprintAction): View
    {
        $rawState = (string) $request->query('state', '');
        $state = OAuthState::decode($rawState);

        if ($state === null || $state->platform !== $platform) {
            return view('connections.oauth-result', ['success' => false, 'message' => 'This connection link is invalid or expired. Please try connecting again from the app.']);
        }

        $team = Team::query()->find($state->teamId);

        if ($team === null) {
            return view('connections.oauth-result', ['success' => false, 'message' => 'We could not find your account. Please try connecting again from the app.']);
        }

        /** @var OAuthChannelAdapter $adapter */
        $adapter = $adapters->driver($platform);

        try {
            $connection = $adapter->completeConnection($team, $state->name, $state->credentials, $state->nonce, $request);
        } catch (\Throwable $e) {
            report($e);

            return view('connections.oauth-result', ['success' => false, 'message' => 'We could not complete the connection. Please try again from the app.']);
        }

        $fingerprintValue = $fingerprintAction->handle($platform, $connection->credentials ?? []);

        if ($fingerprintValue !== null) {
            $connection->update(['fingerprint' => $fingerprintValue]);
        }

        return view('connections.oauth-result', ['success' => true, 'message' => "{$connection->name} is connected. You can return to the app now."]);
    }
}
