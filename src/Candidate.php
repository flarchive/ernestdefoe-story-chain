<?php

namespace ErnestDefoe\StoryChain;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $story_id
 * @property int $round
 * @property int $user_id
 * @property string $body
 * @property Carbon $created_at
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by
 * @property int|null $votes_count
 * @property Story $story
 * @property User|null $user
 */
class Candidate extends AbstractModel
{
    protected $table = 'story_chain_candidates';

    protected $casts = [
        'user_id' => 'integer',
        'round' => 'integer',
        'created_at' => 'datetime',
        'hidden_at' => 'datetime',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /** @param Builder<self> $query */
    public function scopeShown(Builder $query): void
    {
        $query->whereNull('story_chain_candidates.hidden_at');
    }

    /**
     * A round's lines in the order they finish: most votes, then the earliest
     * offered (ids rise with every submission, so a withdrawn and re-offered
     * line counts from its new submission).
     *
     * @param Builder<self> $query
     */
    public function scopeRanked(Builder $query, int $storyId, int $round): void
    {
        $query->where('story_chain_candidates.story_id', $storyId)
            ->where('story_chain_candidates.round', $round)
            ->whereNull('story_chain_candidates.hidden_at')
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('story_chain_candidates.id');
    }
}
