@props(['branding'])
{{-- The brand block. With a logo, the logo; without one, the name as a
     wordmark. Neither is ever written as a literal. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="border:2px solid #ECEFE8;padding:10px 14px;">
            @if ($branding['logo_dark_url'])
                <img src="{{ $branding['logo_dark_url'] }}"
                     alt="{{ $branding['name'] }}"
                     height="28"
                     style="display:block;height:28px;width:auto;border:0;">
            @else
                <span style="font-family:'Archivo Expanded','Archivo',Helvetica,Arial,sans-serif;font-weight:800;font-size:18px;line-height:22px;letter-spacing:{{ strlen($branding['name']) <= 10 ? '2px' : '0.5px' }};text-transform:uppercase;color:#ECEFE8;">
                    {{ $branding['name'] }}
                </span>
            @endif
        </td>
    </tr>
</table>
