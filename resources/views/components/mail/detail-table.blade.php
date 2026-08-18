@props(['rows' => []])
{{-- Key/value details: an order summary, a consultation, a certificate. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin:8px 0 20px;border:2px solid #14170F;">
    @foreach ($rows as $label => $value)
        <tr>
            <th align="left" style="width:42%;padding:10px 14px;background-color:#ECEFE8;border-bottom:{{ $loop->last ? '0' : '2px dashed #CED1CA' }};font-family:'Archivo Expanded','Archivo',Helvetica,Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#575A52;">
                {{ $label }}
            </th>
            <td style="padding:10px 14px;border-bottom:{{ $loop->last ? '0' : '2px dashed #CED1CA' }};font-family:'Lexend',-apple-system,Helvetica,Arial,sans-serif;font-size:15px;color:#14170F;">
                {{ $value }}
            </td>
        </tr>
    @endforeach
</table>
