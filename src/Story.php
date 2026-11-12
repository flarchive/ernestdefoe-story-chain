<?php

namespace ErnestDefoe\StoryChain;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $blurb
 * @property string $cover
 * @property int|null $user_id
 * @property string $status
 * @property int $round
 * @property Carbon $round_ends_at
 * @property int $round_hours
 * @property int|null $max_lines
 * @property int $max_length
 * @property bool $final_round
 * @property int $line_count
 * @property int $contributor_count
 * @property int $candidate_count
 * @property int $vote_count
 * @property Carbon|null $last_line_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property User|null $user
 */
class Story extends AbstractModel
{
    public const ACTIVE = 'active';
    public const FINISHED = 'finished';

    protected $table = 'story_chain_stories';

    public $timestamps = true;

    protected $casts = [
        'round' => 'integer',
        'round_ends_at' => 'datetime',
        'round_hours' => 'integer',
        'max_lines' => 'integer',
        'max_length' => 'integer',
        'final_round' => 'boolean',
        'line_count' => 'integer',
        'contributor_count' => 'integer',
        'candidate_count' => 'integer',
        'vote_count' => 'integer',
        'last_line_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Line::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Open for lines and votes right now: the scheduler may not have closed a round that ran out. */
    public function isOpen(): bool
    {
        return $this->isActive() && $this->round_ends_at->isFuture();
    }

    /** The round now open is the last one: the admin said so, or it brings the story to its length. */
    public function isLastRound(): bool
    {
        return $this->final_round || ($this->max_lines && $this->line_count + 1 >= $this->max_lines);
    }

    /** The story row, locked and re-read inside a transaction: closing, writing and voting on one story take turns. */
    public static function locked(int $id): ?self
    {
        return self::query()->lockForUpdate()->find($id);
    }
}
