{{--
    A demo handout, so the reader, the watermark and the signed link can be
    looked at with a real file behind them.

    Nothing here names the company: a lesson handout is course material, and
    the identity on it is the footer the serving controller stamps at the moment
    somebody opens it, with their own name on it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $lessonTitle }}</title>
    <style>
        @page { margin: 18mm 16mm 22mm; }

        body { font-family: 'DejaVu Sans', sans-serif; color: #14170F; font-size: 11pt; line-height: 1.5; }

        .stencil { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: #575A52; }

        h1 { font-size: 19pt; margin: 2mm 0 4mm; line-height: 1.15; }
        h2 { font-size: 12pt; margin: 7mm 0 1mm; }

        /* The seam, carried onto paper. */
        hr { border: 0; border-top: 3px dashed #F5B711; margin: 0 0 6mm; }
    </style>
</head>
<body>
    <p class="stencil">{{ $courseTitle }}</p>
    <h1>{{ $lessonTitle }}</h1>
    <hr>

    @foreach ($sections as $section)
        <h2>{{ $section['heading'] }}</h2>
        <p>{{ $section['body'] }}</p>
    @endforeach
</body>
</html>
