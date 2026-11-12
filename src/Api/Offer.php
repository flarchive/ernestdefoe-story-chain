<?php

namespace ErnestDefoe\StoryChain\Api;

use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Story;
use Flarum\User\User;

final class Offer
{
    /** Whether the member wrote the story's newest shown line (they sit the next round out when the forum says so). */
    public static function wroteLast(Story $story, User $actor): bool
    {
        return (int) Line::query()->where('story_id', $story->id)->shown()->orderByDesc('position')->value('user_id') === (int) $actor->id;
    }
}
