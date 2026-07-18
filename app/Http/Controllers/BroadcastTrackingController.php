<?php

namespace App\Http\Controllers;

use App\Actions\Settings\UpdateNotificationPreferencesAction;
use App\Models\BroadcastDelivery;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public, unauthenticated endpoints hit from outside the app itself — an
 * email client loading the tracking pixel, or a recipient clicking the
 * unsubscribe link in their inbox (Plan §8.7.5 open-tracking/unsubscribe
 * gaps). Both routes are signed (`URL::signedRoute`, see `BroadcastMail`)
 * so a guessed `{delivery}` id can't be used to mark another recipient's
 * email opened or silently flip their preferences — the framework's
 * `signed` route middleware rejects an invalid/missing signature before
 * either action method ever runs.
 */
class BroadcastTrackingController extends Controller
{
    /**
     * A 1x1 transparent GIF, the standard email open-tracking mechanism —
     * real bytes with a real `image/gif` content type, not a redirect
     * standing in for one. Idempotent: only the first hit stamps
     * `opened_at`, later loads (an email client re-fetching, a user
     * reopening the message) don't move the timestamp.
     */
    private const TRANSPARENT_GIF_BASE64 = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function open(BroadcastDelivery $delivery): Response
    {
        if ($delivery->opened_at === null) {
            $delivery->update(['opened_at' => now()]);
        }

        return response(base64_decode(self::TRANSPARENT_GIF_BASE64), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * One-click unsubscribe (Plan §8.7.5 "marketing emails honor
     * unsubscribe"). Reuses `UpdateNotificationPreferencesAction` — the
     * same action Settings uses — rather than a second preferences-mutation
     * path, so this is a real, permanent opt-out of email notifications,
     * not a broadcast-only toggle.
     */
    public function unsubscribe(BroadcastDelivery $delivery, UpdateNotificationPreferencesAction $updatePreferences): View
    {
        $user = $delivery->user;

        abort_if($user === null, 404);

        $updatePreferences->handle($user, ['email_enabled' => false]);

        if ($delivery->unsubscribed_at === null) {
            $delivery->update(['unsubscribed_at' => now()]);
        }

        return view('broadcasts.unsubscribed');
    }
}
