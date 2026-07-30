<!doctype html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <h2 style="margin-bottom: 8px;">Your last payment didn't go through</h2>
    <p><strong>Nothing has changed yet</strong> — your stores, rules, and history are all still active while the payment is retried.</p>

    @if ($provider === 'apple')
        <p>To fix it, update your payment method in your Apple account: <strong>Settings &rarr; your name &rarr; Payment &amp; Shipping</strong>. Apple will retry the charge automatically.</p>
    @elseif ($provider === 'google')
        <p>To fix it, update your payment method in Google Play: <strong>Play Store &rarr; Payments &amp; subscriptions</strong>. Google will retry the charge automatically.</p>
    @else
        <p>To fix it, update your payment method with whichever store you subscribed through — the App Store or Google Play. They'll retry the charge automatically.</p>
    @endif

    <p>If the retries don't succeed, the subscription lapses and extra stores pause, custom rules switch off, and history trims back. Nothing is deleted, and it all returns the moment payment succeeds.</p>
</body>
</html>
