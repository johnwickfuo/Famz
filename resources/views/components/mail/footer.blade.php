@props([
    'branding',
    'showUnsubscribe' => false,
    'unsubscribeUrl' => null,
])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="font-family:'Lexend',-apple-system,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:#575A52;">
            <strong style="color:#14170F;">{{ $branding['name'] }}</strong>
            @if ($branding['rc_number'])
                <span> &middot; RC {{ $branding['rc_number'] }}</span>
            @endif

            @if ($branding['address'])
                <br>{{ $branding['address'] }}
            @endif

            @if ($branding['phone'] || $branding['email'])
                <br>
                @if ($branding['phone'])<a href="tel:{{ $branding['phone'] }}" style="color:#0E5138;">{{ $branding['phone'] }}</a>@endif
                @if ($branding['phone'] && $branding['email']) &middot; @endif
                @if ($branding['email'])<a href="mailto:{{ $branding['email'] }}" style="color:#0E5138;">{{ $branding['email'] }}</a>@endif
            @endif

            {{-- Transactional mail carries no unsubscribe link; anything the
                 recipient did not ask for individually must. --}}
            @if ($showUnsubscribe && $unsubscribeUrl)
                <br><br>
                <a href="{{ $unsubscribeUrl }}" style="color:#575A52;text-decoration:underline;">
                    {{ __('Unsubscribe from these updates') }}
                </a>
            @endif
        </td>
    </tr>
</table>
