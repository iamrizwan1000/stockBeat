<!doctype html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <h2 style="margin-bottom: 8px;">{{ $title }}</h2>
    <p>{{ $body }}</p>

    <hr style="margin-top: 24px; border: none; border-top: 1px solid #e1e1e1;">
    <p style="font-size: 12px; color: #8a8a8a;">
        <a href="{{ $unsubscribeUrl }}" style="color: #8a8a8a;">Unsubscribe from marketing emails</a>
    </p>

    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:block;border:0;">
</body>
</html>
