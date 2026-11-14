@if ($paginator->hasPages())
    <nav aria-label="Navigasi halaman" class="flex items-center gap-2">
        @if ($paginator->onFirstPage())
            <span class="btn-secondary btn-sm cursor-not-allowed opacity-50" aria-disabled="true">Sebelumnya</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="btn-secondary btn-sm" rel="prev">Sebelumnya</a>
        @endif

        <span class="text-xs text-gray-500">Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="btn-secondary btn-sm" rel="next">Berikutnya</a>
        @else
            <span class="btn-secondary btn-sm cursor-not-allowed opacity-50" aria-disabled="true">Berikutnya</span>
        @endif
    </nav>
@endif
