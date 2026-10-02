@if ($paginator->hasPages())
<nav class="pagination" aria-label="Guest list pages">
    @if ($paginator->onFirstPage())
        <span class="pagination-disabled" aria-disabled="true">← Previous</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Previous</a>
    @endif

    <div class="pagination-pages">
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pagination-gap">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pagination-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
    </div>

    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next →</a>
    @else
        <span class="pagination-disabled" aria-disabled="true">Next →</span>
    @endif
</nav>
@endif
