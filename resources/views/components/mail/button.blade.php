@props(['href'])
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0;">
    <tr>
        <td style="border-radius:8px; background-color:#191C18;">
            <a href="{{ $href }}" style="display:inline-block; padding:11px 20px; font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">
                {{ $slot }}
            </a>
        </td>
    </tr>
</table>
