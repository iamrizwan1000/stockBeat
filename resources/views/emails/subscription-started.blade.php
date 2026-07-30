<!doctype html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a;">
    @if ($reason === 'plan_change')
        <h2 style="margin-bottom: 8px;">You're now on {{ $planName }}</h2>
        <p>Your plan change went through, and your {{ $planName }} limits are active right now — no need to reconnect anything.</p>
    @elseif ($reason === 'payment_recovered')
        <h2 style="margin-bottom: 8px;">Your payment went through</h2>
        <p>The retry succeeded, so you can ignore the earlier message about your payment method. <strong>Nothing was ever interrupted</strong> — your stores, rules, and history stayed active throughout, and {{ $planName }} continues as normal.</p>
    @elseif ($reason === 'reactivated')
        <h2 style="margin-bottom: 8px;">Welcome back to {{ $planName }}</h2>
        <p>Your subscription is active again. The stores that were paused are running, your custom rules are switched back on, and your full history is visible again — all exactly as you left it, since nothing was ever deleted.</p>
    @else
        <h2 style="margin-bottom: 8px;">Your {{ $planName }} subscription is active</h2>
        <p>Thanks for subscribing. Your {{ $planName }} limits are active right now, and any stores or rules that were paused have been switched back on.</p>
    @endif

    <p style="color: #555;">Your receipt comes from the App Store or Google Play, whichever you subscribed through — that's also where you can manage or cancel the subscription at any time.</p>
</body>
</html>
