<?php

namespace ErnestDefoe\StoryChain;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $story_id
 * @property int $round
 * @property int $candidate_id
 * @property int $user_id
 * @property Carbon $created_at
 */
class Vote extends AbstractModel
{
    protected $table = 'story_chain_votes';

    protected $casts = [
        'user_id' => 'integer',
        'round' => 'integer',
        'candidate_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
