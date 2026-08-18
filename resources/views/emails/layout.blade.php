{{--
    The base email layout.

    Everything here is inline CSS and table-free where possible, because Gmail,
    Yahoo Mail and the Android Gmail app between them cover most of this
    audience. The colours and type are the platform's own tokens
    (docs/design-system.md), and the company name and logo come from
    BrandingService via the view composer — never from a literal.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('subject', $branding['name'])</title>
    <style>
        /* Kept minimal: most clients strip anything clever. */
        body { margin: 0; padding: 0; background-color: #ECEFE8; }
        a { color: #0E5138; }
        @media only screen and (max-width: 600px) {
            .wrap { width: 100% !important; }
            .pad { padding-left: 20px !important; padding-right: 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#ECEFE8;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        @yield('preheader', '')
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#ECEFE8;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" border="0"
                       style="width:600px;max-width:600px;background-color:#FFFFFF;border:2px solid #14170F;">

                    {{-- Header: the stitched sack label, in email-safe markup. --}}
                    <tr>
                        <td class="pad" style="background-color:#0E5138;padding:20px 28px;">
                            <x-mail.brand-header :branding="$branding" />
                        </td>
                    </tr>

                    {{-- The seam. --}}
                    <tr>
                        <td style="font-size:0;line-height:0;height:4px;background-color:#F5B711;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td class="pad" style="padding:28px;font-family:'Lexend',-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:24px;color:#14170F;">
                            @yield('content')
                        </td>
                    </tr>

                    <tr>
                        <td class="pad" style="padding:0 28px;">
                            <x-mail.seam />
                        </td>
                    </tr>

                    <tr>
                        <td class="pad" style="padding:20px 28px 28px;">
                            <x-mail.footer
                                :branding="$branding"
                                :show-unsubscribe="$showUnsubscribe ?? false"
                                :unsubscribe-url="$unsubscribeUrl ?? null"
                            />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
