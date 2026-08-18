@props(['url', 'variant' => 'primary'])
@php
    $background = $variant === 'enamel' ? '#0E5138' : '#F5B711';
    $foreground = $variant === 'enamel' ? '#ECEFE8' : '#14170F';
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px;">
    <tr>
        <td style="background-color:{{ $background }};border:2px solid #14170F;">
            <a href="{{ $url }}"
               style="display:inline-block;padding:12px 22px;font-family:'Archivo Expanded','Archivo',Helvetica,Arial,sans-serif;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;text-decoration:none;color:{{ $foreground }};">
                {{ $slot }}
            </a>
        </td>
    </tr>
</table>
