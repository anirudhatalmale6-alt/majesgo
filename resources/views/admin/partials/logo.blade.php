{{-- Logo Majes Drive. $dark=true => "Majes" en blanco (fondo oscuro del panel).

     El símbolo es el ícono real de la marca, el mismo que va en el celular, no una
     reinterpretación: así el panel y las apps se ven como la misma cosa. Se embebe el PNG
     dentro del SVG con <image> para que el conjunto siga siendo un único elemento escalable
     y las medidas ya existentes (.mg-logo{width:170px}) sigan valiendo.

     ⚠ Los amarillos de la marca anterior se fueron: la identidad nueva es verde en dos
     tonos. Dejar el pin amarillo al lado del texto nuevo era lo que hacía que el cambio de
     marca se notara a medias. --}}
@php($dark = $dark ?? true)
<svg class="mg-logo" viewBox="0 0 268 58" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Majes Drive">
    <defs>
        <clipPath id="mgsq"><rect x="2" y="7" width="44" height="44" rx="11"/></clipPath>
    </defs>
    <image href="/icons/icon-192.png" x="2" y="7" width="44" height="44" clip-path="url(#mgsq)"/>
    <text x="58" y="38" font-family="Poppins, sans-serif" font-weight="700" font-size="30" letter-spacing="-0.5">
        <tspan fill="{{ $dark ? '#FFFFFF' : '#0D0D0D' }}">Majes </tspan><tspan fill="#34C759">Drive</tspan>
    </text>
</svg>
