<?php

namespace ErnestDefoe\StoryChain;

use Carbon\Carbon;
use Flarum\User\User;

/**
 * Takes a member out of Story Chain: their offered lines and votes go, their
 * name comes off their story lines and the stories they started. Erasing
 * (GDPR) also blanks their story lines' text and hides them for good; plain
 * anonymizing and account deletion keep the words, nameless. Either way the
 * stories' line and writer counts are recounted, finished stories included.
 */
class Forget
{
    public static function user(User $user, bool $erase): void
    {
        $stories = Line::query()->where('user_id', $user->id)->distinct()->pluck('story_id');

        Vote::query()->where('user_id', $user->id)->delete();
        Candidate::query()->where('user_id', $user->id)->delete();
        if ($erase) {
            Line::query()->where('user_id', $user->id)->update(['body' => '', 'hidden_at' => Carbon::now()]);
        }
        Line::query()->where('user_id', $user->id)->update(['user_id' => null]);
        Story::query()->where('user_id', $user->id)->update(['user_id' => null]);

        foreach ($stories as $id) {
            Story::query()->where('id', $id)->update([
                'line_count' => Line::query()->where('story_id', $id)->shown()->count(),
                'contributor_count' => Closer::contributors((int) $id),
            ]);
        }
        if ($stories->isNotEmpty()) {
            resolve(CardCache::class)->forget();
        }
    }
}
