{{-- Member text (the title and the line) never goes through Markdown: placeholders are formatted, then swapped for the escaped text. --}}
<x-mail::html.notification>
    <x-slot:body>
        {!! strtr($formatter->convert($translator->trans('ernestdefoe-story-chain.email.won.html.body', [
            '{title}' => 'SCTITLEX',
            '{round}' => $blueprint->round,
            '{line}' => 'SCLINEX',
            '{url}' => $url->to('forum')->route('story-chain.story', ['id' => $blueprint->story->id.'-'.$blueprint->story->slug]),
        ])), ['SCTITLEX' => e($blueprint->story->title), 'SCLINEX' => e($blueprint->line)]) !!}
    </x-slot:body>
</x-mail::html.notification>
