<x-mail.layout preheader="Your last StockBeat payment didn't go through — nothing has changed yet.">
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">Your last payment didn't go through</h2>
    <p style="margin:0 0 14px;"><strong>Nothing has changed yet</strong> — your stores, rules, and history are all still active while the payment is retried.</p>

    @if ($provider === 'apple')
        <p style="margin:0 0 4px;">To fix it, update your payment method in your Apple account: <strong>Settings &rarr; your name &rarr; Payment &amp; Shipping</strong>. Apple will retry the charge automatically.</p>
        <x-mail.button href="https://apps.apple.com/account/subscriptions">Update payment method</x-mail.button>
    @elseif ($provider === 'google')
        <p style="margin:0 0 4px;">To fix it, update your payment method in Google Play: <strong>Play Store &rarr; Payments &amp; subscriptions</strong>. Google will retry the charge automatically.</p>
        <x-mail.button href="https://play.google.com/store/account/subscriptions">Update payment method</x-mail.button>
    @else
        <p style="margin:0;">To fix it, update your payment method with whichever store you subscribed through — the App Store or Google Play. They'll retry the charge automatically.</p>
    @endif

    <div style="margin:18px 0 0; padding-top:18px; border-top:1px solid #EDEEE9; font-size:13px; color:#757872;">
        If the retries don't succeed, the subscription lapses and extra stores pause, custom rules switch off, and history trims back. Nothing is deleted, and it all returns the moment payment succeeds.
    </div>
</x-mail.layout>
