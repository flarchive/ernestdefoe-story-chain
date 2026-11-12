<?php

namespace ErnestDefoe\StoryChain;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $story_id
 * @property int $position
 * @property int $round
 * @property int|null $user_id
 * @property string $body
 * @property int $votes
 * @property int $rivals
 * @property Carbon $created_at
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by
 * @property Story $story
 * @property User|null $user
 */
class Line extends AbstractModel
{
    protected $table = 'story_chain_lines';

    protected $casts = [
        'user_id' => 'integer',
        'position' => 'integer',
        'round' => 'integer',
        'votes' => 'integer',
        'rivals' => 'integer',
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

    /**
     * Fix a line's wording. Its writer may while it is the newest line of a
     * story still being written (the opening line until the first round
     * closes, a winning line until the next one lands), and a winning line
     * only by a typo's worth: it won with its own words. Moderators may fix
     * any line that wasn't erased. Returns an error code, or null once saved.
     */
    public function rewrite(Story $story, User $actor, mixed $text): ?string
    {
        $moderator = $actor->can(Settings::MODERATE);
        $writer = $actor->id !== null && $this->user_id === $actor->id
            && $story->isActive() && $this->hidden_at === null && $this->round === $story->round - 1;
        if (! $moderator && ! $writer) {
            return 'line_locked';
        }
        if ($this->body === '') {
            return 'line_erased';
        }

        $text = Text::clean($text);
        if (Text::length($text) < 2) {
            return 'line_empty';
        }
        $limit = max($this->round === 0 ? Settings::MAX_LENGTH : $story->max_length, Text::length($this->body));
        if (Text::length($text) > $limit) {
            return 'line_too_long';
        }
        if (! $moderator && $this->round > 0 && levenshtein($this->body, $text) > max(12, intdiv(strlen($this->body), 5))) {
            return 'edit_too_big';
        }

        $this->body = $text;
        $this->save();

        return null;
    }

    /** @param Builder<self> $query */
    public function scopeShown(Builder $query): void
    {
        $query->whereNull('story_chain_lines.hidden_at');
    }
}
