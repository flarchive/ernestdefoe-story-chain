<?php

namespace ErnestDefoe\StoryChain;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The index page's card (the newest line of the busiest story), kept in the
 * cache so the forum payload costs no query. Anything that changes what the
 * card shows forgets it: a round closing, a line offered or removed, a
 * story started, edited or deleted.
 */
class CardCache
{
    private const KEY = 'ernestdefoe-story-chain.card';

    public function __construct(private readonly Cache $cache)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(): ?array
    {
        $card = $this->cache->remember(self::KEY, 600, fn () => $this->build() ?? ['none' => true]);

        return isset($card['none']) ? null : $card;
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }

    /** @return array<string, mixed>|null */
    private function build(): ?array
    {
        /** @var Story|null $story */
        $story = Story::query()->where('status', Story::ACTIVE)->orderByDesc('updated_at')->orderByDesc('id')->first();
        if (! $story) {
            return null;
        }

        /** @var Line|null $last */
        $last = Line::query()->where('story_id', $story->id)->shown()->with('user')->orderByDesc('position')->first();

        return [
            'id' => $story->id,
            'slug' => $story->slug,
            'title' => $story->title,
            'cover' => $story->cover,
            'round' => $story->round,
            'endsAt' => $story->round_ends_at->toIso8601String(),
            'lines' => $story->line_count,
            'maxLines' => $story->max_lines,
            'last' => $last ? $last->body : null,
            'by' => $last?->user?->display_name,
            'offered' => Candidate::query()->where('story_id', $story->id)->where('round', $story->round)->shown()->count(),
        ];
    }
}
