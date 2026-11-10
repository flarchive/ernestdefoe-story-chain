<?php

namespace ErnestDefoe\StoryChain\Http;

use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The /stories pages as the server sends them: a title, a description and a
 * canonical URL, and for a story its text, so search engines and link
 * previews read the story itself. Only for viewers who may read stories.
 */
class PageContent
{
    public function __construct(private readonly UrlGenerator $url, private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $title = (string) $this->translator->trans('ernestdefoe-story-chain.forum.page.title');
        $document->title = $title;
        $document->canonicalUrl = $this->url->to('forum')->route('story-chain');

        $id = (int) Arr::get($request->getQueryParams(), 'id');
        if (! $id || ! RequestUtil::getActor($request)->can(Settings::VIEW)) {
            return $document;
        }

        /** @var Story|null $story */
        $story = Story::query()->find($id);
        if (! $story) {
            return $document;
        }

        $lines = Line::query()->where('story_id', $story->id)->shown()->orderBy('position')->limit(300)->pluck('body')->all();
        $text = implode(' ', $lines);

        $document->title = $story->title;
        $document->canonicalUrl = $this->url->to('forum')->route('story-chain.story', ['id' => $story->id.'-'.$story->slug]);
        $document->meta['description'] = mb_substr($story->blurb ?: $text, 0, 160);
        $document->content = '<article><h1>'.e($story->title).'</h1><p>'.e($text).'</p></article>';

        return $document;
    }
}
