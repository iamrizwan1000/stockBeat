<x-mail.layout preheader="Test send — {{ $title }}">
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
        <tr>
            <td style="background-color:#fff4e5; color:#8a5300; padding:6px 12px; border-radius:6px; font-size:13px; font-weight:600;">
                Test send — variables rendered with placeholder values
            </td>
        </tr>
    </table>
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">{{ $title }}</h2>
    <p style="margin:0;">{{ $body }}</p>
</x-mail.layout>
