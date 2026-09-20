@if ($document)
    {{-- The dialog is height-capped so a long document scrolls inside it rather
         than growing past the viewport; see ~/less/markdown-modal.less. --}}
    <article class="teamdocs-modal" data-teamdocs-key="{{ $document['key'] }}">
        <header class="teamdocs-modal__header">
            <h1 class="teamdocs-modal__title">{{ $document['title'] }}</h1>
        </header>
        {{-- tabindex makes the scroll container reachable by keyboard. --}}
        <div class="teamdocs-modal__body md-body" tabindex="0">
            {{-- HTML is produced and sanitized by the allowlisted Documents service. --}}
            {!! $document['html'] !!}
        </div>
    </article>
@else
    <div class="teamdocs-modal teamdocs-modal--error" role="alert">
        <header class="teamdocs-modal__header">
            <h1 class="teamdocs-modal__title">团队文档</h1>
        </header>
        <div class="teamdocs-modal__body">
            <p>{{ $error ?? '这份团队文档暂时无法打开。' }}</p>
        </div>
    </div>
@endif
