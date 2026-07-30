@php
    $safePlanName = e($planName);
    [$preheader, $heading, $message] = match ($reason) {
        'plan_change' => [
            "You're now on StockBeat {$planName}.",
            "You're now on {$planName}",
            "Your plan change went through, and your {$safePlanName} limits are active right now — no need to reconnect anything.",
        ],
        'payment_recovered' => [
            'Your StockBeat payment went through.',
            'Your payment went through',
            "The retry succeeded, so you can ignore the earlier message about your payment method. <strong>Nothing was ever interrupted</strong> — your stores, rules, and history stayed active throughout, and {$safePlanName} continues as normal.",
        ],
        'reactivated' => [
            "Welcome back to StockBeat {$planName}.",
            "Welcome back to {$planName}",
            'Your subscription is active again. The stores that were paused are running, your custom rules are switched back on, and your full history is visible again — all exactly as you left it, since nothing was ever deleted.',
        ],
        default => [
            "Your StockBeat {$planName} subscription is active.",
            "Your {$planName} subscription is active",
            "Thanks for subscribing. Your {$safePlanName} limits are active right now, and any stores or rules that were paused have been switched back on.",
        ],
    };
@endphp
<x-mail.layout :preheader="$preheader">
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">{{ $heading }}</h2>
    <p style="margin:0;">{!! $message !!}</p>

    <div style="margin:18px 0 0; padding-top:18px; border-top:1px solid #EDEEE9; font-size:13px; color:#757872;">
        Your receipt comes from the App Store or Google Play, whichever you subscribed through — that's also where you can manage or cancel the subscription at any time.
    </div>
</x-mail.layout>
