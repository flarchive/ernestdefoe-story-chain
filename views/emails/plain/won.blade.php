<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-story-chain.email.won.plain.body', [
'{title}' => $blueprint->story->title,
'{round}' => $blueprint->round,
'{line}' => $blueprint->line,
'{url}' => $url->to('forum')->route('story-chain.story', ['id' => $blueprint->story->id.'-'.$blueprint->story->slug]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
