@if($paginator->hasPages())
    <nav class="pp-pager" aria-label="Paginación">
        <span class="pp-pager-current">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
        <div class="pp-pager-actions">
            @if($paginator->onFirstPage())
                <span class="pp-pager-button is-disabled" aria-disabled="true">Anterior</span>
            @else
                <a class="pp-pager-button" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>
            @endif
            @if($paginator->hasMorePages())
                <a class="pp-pager-button" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a>
            @else
                <span class="pp-pager-button is-disabled" aria-disabled="true">Siguiente</span>
            @endif
        </div>
    </nav>
@endif
