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

    /** @param Builder<self> $query */
    public function scopeShown(Builder $query): void
    {
        $query->whereNull('story_chain_lines.hidden_at');
    }
}
