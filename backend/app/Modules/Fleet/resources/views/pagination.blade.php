@if($paginator->hasPages())
<nav class="ret-pagination" aria-label="Páginas"><span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }} resultados</span><div>
@if($paginator->previousPageUrl())<a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Anterior</a>@endif
<span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
@if($paginator->nextPageUrl())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente →</a>@endif
</div></nav>
@endif
