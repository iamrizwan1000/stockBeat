@if ($daysRemaining <= 0)
<x-mail.layout preheader="Your 7-day StockBeat trial ends today.">
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">Your trial ends today</h2>
    <p style="margin:0;">Your 7-day StockBeat trial ends today. Upgrade now to keep every store connected, all your custom rules active, and full order history.</p>
</x-mail.layout>
@else
<x-mail.layout preheader="{{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }} left on your StockBeat trial.">
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">{{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }} left on your trial</h2>
    <p style="margin:0;">After your trial ends, extra stores pause, custom rules turn off, and order history trims to 7 days — nothing is deleted, and it all comes back the moment you upgrade.</p>
</x-mail.layout>
@endif
