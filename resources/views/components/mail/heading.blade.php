@props(['level' => 1])
<h{{ $level }} style="margin:0 0 12px;font-family:'Archivo Expanded','Archivo',Helvetica,Arial,sans-serif;font-weight:700;font-size:{{ $level === 1 ? '24px' : '18px' }};line-height:{{ $level === 1 ? '30px' : '24px' }};color:#14170F;">
    {{ $slot }}
</h{{ $level }}>
