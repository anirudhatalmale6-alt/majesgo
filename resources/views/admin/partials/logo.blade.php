{{-- Logo MajesGo recreado en SVG (nítido y escalable). $dark=true => "Majes" en blanco --}}
@php($dark = $dark ?? true)
<svg class="mg-logo" viewBox="0 0 250 58" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="MajesGo">
    {{-- líneas de velocidad --}}
    <rect x="2"  y="12" width="16" height="4" rx="2" fill="#FFC107"/>
    <rect x="0"  y="23" width="20" height="4" rx="2" fill="#FFC107"/>
    <rect x="4"  y="34" width="14" height="4" rx="2" fill="#FFC107"/>
    {{-- pin --}}
    <path d="M40 4 a18 18 0 0 1 18 18 c0 12 -18 30 -18 30 c0 0 -18 -18 -18 -30 a18 18 0 0 1 18 -18 z" fill="#FFC107"/>
    <text x="40" y="30" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="800" font-size="24" fill="#0D0D0D">M</text>
    {{-- wordmark --}}
    <text x="66" y="38" font-family="Poppins, sans-serif" font-weight="700" font-size="30" letter-spacing="-0.5">
        <tspan fill="{{ $dark ? '#FFFFFF' : '#0D0D0D' }}">Majes</tspan><tspan fill="#00C853">Go</tspan>
    </text>
</svg>
