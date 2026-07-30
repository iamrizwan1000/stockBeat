@props(['preheader' => null])
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>StockBeat</title>
</head>
<body style="margin:0; padding:0; background-color:#F3F4EE; -webkit-text-size-adjust:100%; text-size-adjust:100%;">
@if ($preheader)
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; opacity:0;">{{ $preheader }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F3F4EE;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                <tr>
                    <td style="padding:0 8px 20px;">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="vertical-align:middle; padding-right:8px;">
                                    <img src="{{ asset('assets/logo1.png') }}" width="28" height="28" alt="StockBeat" style="display:block; border-radius:6px;">
                                </td>
                                <td style="vertical-align:middle;">
                                    <span style="font-family:'Hanken Grotesk','Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:16px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">StockBeat</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#ffffff; border:1px solid #D8DAD4; border-radius:12px;">
                        <div style="padding:32px; font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:15px; line-height:1.6; color:#191C18;">
                            {{ $slot }}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 8px 0;">
                        <p style="margin:0; font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:12px; line-height:1.6; color:#757872;">
                            StockBeat &middot; <a href="{{ config('app.url') }}" style="color:#757872; text-decoration:underline;">{{ parse_url(config('app.url'), PHP_URL_HOST) ?? 'stockbeat.qistpay.org' }}</a>
                        </p>
                        @isset($footer)
                        <div style="margin-top:8px; font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:12px; line-height:1.6; color:#757872;">
                            {{ $footer }}
                        </div>
                        @endisset
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
