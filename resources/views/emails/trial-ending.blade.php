<!doctype html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a;">
    @if ($daysRemaining <= 0)
        <h2 style="margin-bottom: 8px;">Your trial ends today</h2>
        <p>Your 7-day StockBeat trial ends today. Upgrade now to keep every store connected, all your custom rules active, and full order history.</p>
    @else
        <h2 style="margin-bottom: 8px;">{{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }} left on your trial</h2>
        <p>After your trial ends, extra stores pause, custom rules turn off, and order history trims to 7 days — nothing is deleted, and it all comes back the moment you upgrade.</p>
    @endif
</body>
</html>
