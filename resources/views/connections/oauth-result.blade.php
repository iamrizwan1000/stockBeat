<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $success ? 'Connected' : 'Connection failed' }} — StockBeat</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #f6f6f7; margin: 0; display: flex; min-height: 100vh; align-items: center; justify-content: center; }
        .card { background: #fff; border-radius: 12px; padding: 40px; max-width: 420px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { color: #555; margin: 0; }
        .icon { font-size: 40px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{{ $success ? '✅' : '⚠️' }}</div>
        <h1>{{ $success ? 'Store connected' : 'Connection failed' }}</h1>
        <p>{{ $message }}</p>
    </div>
    <script>
        window.location.href = {{ Js::from($deepLink) }};
    </script>
</body>
</html>
