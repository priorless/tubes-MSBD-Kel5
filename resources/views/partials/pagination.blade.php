@if ($paginator->hasPages())
    <nav aria-label="Navigasi halaman">
        <ul class="pagination pagination-sm m-0">
            <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">
                <a class="page-link" href="{{ $paginator->previousPageUrl() ?? '#' }}" aria-label="Halaman sebelumnya">Sebelumnya</a>
            </li>
            <li class="page-item disabled">
                <span class="page-link">Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</span>
            </li>
            <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">
                <a class="page-link" href="{{ $paginator->nextPageUrl() ?? '#' }}" aria-label="Halaman berikutnya">Berikutnya</a>
            </li>
        </ul>
    </nav>
@endif
