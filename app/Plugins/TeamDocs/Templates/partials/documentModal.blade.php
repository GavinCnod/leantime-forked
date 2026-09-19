@if ($document)
    <article class="teamdocs-modal tw-flex tw-flex-col tw-gap-4" data-teamdocs-key="{{ $document['key'] }}">
        <header class="teamdocs-modal__header">
            <h1 class="tw-text-xl tw-font-semibold">{{ $document['title'] }}</h1>
            <p class="tw-text-sm tw-opacity-70">{{ $document['filename'] }}</p>
        </header>
        <div class="teamdocs-modal__content tw-overflow-y-auto" tabindex="0">
            {{-- HTML is produced by the allowlisted Documents service, not raw Markdown. --}}
            {!! $document['html'] !!}
        </div>
    </article>
@else
    <div class="teamdocs-modal teamdocs-modal--error" role="alert">
        <h1 class="tw-text-xl tw-font-semibold">团队文档</h1>
        <p>{{ $error ?? '这份团队文档暂时无法打开。' }}</p>
    </div>
@endif
