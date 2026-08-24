{{--
    Paginador del panel.

    El que trae Laravel viene con clases de Tailwind y en español no está: en el panel
    salía «Showing 1 to 30 of 275 results / Previous / Next», sin estilo y saliéndose de
    la pantalla en el teléfono. Este produce enlaces sueltos que ya encajan con el CSS
    que el panel tenía preparado (.pagi a, .pagi span, .pagi .on).
--}}
@if ($paginator->hasPages())
    @php
        // Ventana de páginas alrededor de la actual: con 20 páginas no tiene sentido
        // pintar los 20 números en una pantalla de teléfono.
        $last    = $paginator->lastPage();
        $current = $paginator->currentPage();
        $from    = max(1, $current - 2);
        $to      = min($last, $current + 2);
    @endphp

    @if ($paginator->onFirstPage())
        <span class="off" aria-disabled="true">‹ Anterior</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Anterior</a>
    @endif

    @if ($from > 1)
        <a href="{{ $paginator->url(1) }}">1</a>
        @if ($from > 2)<span class="off">…</span>@endif
    @endif

    @for ($i = $from; $i <= $to; $i++)
        @if ($i == $current)
            <span class="on" aria-current="page">{{ $i }}</span>
        @else
            <a href="{{ $paginator->url($i) }}">{{ $i }}</a>
        @endif
    @endfor

    @if ($to < $last)
        @if ($to < $last - 1)<span class="off">…</span>@endif
        <a href="{{ $paginator->url($last) }}">{{ $last }}</a>
    @endif

    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente ›</a>
    @else
        <span class="off" aria-disabled="true">Siguiente ›</span>
    @endif

    <span class="cuenta">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span>
@endif
