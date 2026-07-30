<x-mail.layout preheader="Your sign-in code is {{ $code }} — it expires in 10 minutes.">
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">Your sign-in code</h2>
    <p style="margin:0 0 18px; color:#454843;">Enter this code in StockBeat to finish signing in.</p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px; background-color:#F3F4EE; border:1px solid #D8DAD4; border-radius:8px;">
        <tr>
            <td style="padding:16px 24px; font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:30px; font-weight:700; letter-spacing:6px; color:#191C18;">
                {{ $code }}
            </td>
        </tr>
    </table>

    <p style="margin:0; font-size:13px; color:#757872;">This code expires in 10 minutes. If you didn't request it, you can safely ignore this email.</p>
</x-mail.layout>
