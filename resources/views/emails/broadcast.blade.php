<x-mail.layout :preheader="$title">
    <h2 style="margin:0 0 12px; font-size:19px; font-weight:600; color:#191C18; letter-spacing:-0.01em;">{{ $title }}</h2>
    <p style="margin:0;">{{ $body }}</p>
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:block; border:0; margin-top:18px;">

    <x-slot:footer>
        <a href="{{ $unsubscribeUrl }}" style="color:#757872; text-decoration:underline;">Unsubscribe from marketing emails</a>
    </x-slot:footer>
</x-mail.layout>
