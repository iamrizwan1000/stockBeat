<x-mail.layout preheader="{{ $inviterName }} invited you to join {{ $teamName }} on StockBeat.">
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">You've been invited to {{ $teamName }}</h2>
    <p style="margin:0 0 14px;">{{ $inviterName }} invited you to join their team on StockBeat as a <strong>{{ ucfirst($role) }}</strong>.</p>
    <p style="margin:0;">Open the StockBeat app and sign in with the email address this invite was sent to — you'll join {{ $teamName }} automatically, no invite code needed.</p>
</x-mail.layout>
