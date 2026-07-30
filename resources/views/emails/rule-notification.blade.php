<x-mail.layout preheader="{{ $title }}">
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">{{ $title }}</h2>
    <p style="margin:0;">{{ $body }}</p>

    <div style="margin:18px 0 0; padding-top:18px; border-top:1px solid #EDEEE9; font-size:12px; color:#757872;">
        Sent because one of your StockBeat rules fired. Manage which rules email you from the Rules tab in the app.
    </div>
</x-mail.layout>
