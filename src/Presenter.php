<?php

namespace ErnestDefoe\StoryChain;

use Flarum\Http\SlugManager;
use Flarum\User\User;

/** Stories and people as the browser sees them: plain arrays, ids for people, never a model. */
class Presenter
{
    public function __construct(private readonly SlugManager $slugs)
    {
    }

    /** @return array<string, mixed> */
    public function story(Story $story): array
    {
        return [
            'id' => $story->id,
            'slug' => $story->slug,
            'title' => $story->title,
            'blurb' => $story->blurb,
            'cover' => $story->cover,
            'status' => $story->status,
            'round' => $story->round,
            'endsAt' => $story->round_ends_at->toIso8601String(),
            'roundHours' => $story->round_hours,
            'maxLines' => $story->max_lines,
            'maxLength' => $story->max_length,
            'finalRound' => $story->final_round,
            'lastRound' => $story->isActive() && $story->isLastRound(),
            'lines' => $story->line_count,
            'contributors' => $story->contributor_count,
            'candidates' => $story->candidate_count,
            'votes' => $story->vote_count,
            'creator' => $story->user_id,
            'createdAt' => $story->created_at->toIso8601String(),
            'lastLineAt' => $story->last_line_at?->toIso8601String(),
            'finishedAt' => $story->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @param list<int|null> $ids
     * @return list<array<string, mixed>>
     */
    public function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }

        $slugger = $this->slugs->forResource(User::class);

        return User::query()->whereIn('id', $ids)->get()->map(fn (User $user) => [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'slug' => $slugger->toSlug($user),
            'avatarUrl' => $user->avatar_url,
        ])->values()->all();
    }
}
