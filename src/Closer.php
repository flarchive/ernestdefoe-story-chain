<?php

namespace ErnestDefoe\StoryChain;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Job\SendRoundNews;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Closes rounds: the most-voted line (ties: the earliest offered) joins the
 * story, credited to its writer, and the next round opens, or the story
 * finishes. Safe to run twice at once or again after a crash:
 *
 *  - the story row is locked and re-read, and nothing happens unless its
 *    round is still the one we came to close and has run out;
 *  - the story moves on with a conditional UPDATE on that round (engines
 *    without row locks, like SQLite, still let only one closer through);
 *  - a line is unique per (story, round), so a second insert can't land.
 *
 * Alerts go out on the queue after the commit (SendRoundNews), never in the
 * request or run that closed the round.
 */
class Closer
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Queue $queue,
        private readonly CardCache $card,
        private readonly LoggerInterface $log,
    ) {
    }

    /** Every round that has run out. Returns how many closed. */
    public function run(): int
    {
        $closed = 0;
        $due = Story::query()->where('status', Story::ACTIVE)->where('round_ends_at', '<=', Carbon::now())->orderBy('round_ends_at')->limit(200)->pluck('id');

        foreach ($due as $id) {
            try {
                $closed += $this->close((int) $id) ? 1 : 0;
            } catch (\Throwable $e) {
                // One story's failure must not stop the others.
                $this->log->error("[story-chain] closing story {$id} failed: ".$e->getMessage());
            }
        }

        return $closed;
    }

    /**
     * Close the story's open round if it has run out (or right now, for a
     * moderator's "close this round"). Returns false when there was nothing
     * to close: another closer got there first, or the round is still open.
     */
    public function close(int $storyId, bool $now = false): bool
    {
        try {
            $news = $this->db->transaction(fn () => $this->closeLocked($storyId, $now));
        } catch (RaceLost) {
            return false;
        } catch (QueryException $e) {
            // The unique (story, round) line: a racing closer won. Anything else is real.
            if ($this->isDuplicate($e)) {
                return false;
            }
            throw $e;
        }

        if ($news === null) {
            return false;
        }

        $this->card->forget();
        if ($news['line'] || $news['finished']) {
            $this->queue->push(new SendRoundNews($storyId, $news['round'], $news['line'], $news['finished']));
        }

        return true;
    }

    /**
     * A moderator's early close gives the next round its full length from now.
     *
     * @return array{round: int, line: int|null, finished: bool}|null
     */
    private function closeLocked(int $storyId, bool $now): ?array
    {
        $story = Story::locked($storyId);
        if (! $story || ! $story->isActive() || (! $now && $story->round_ends_at->isFuture())) {
            return null;
        }

        $round = $story->round;
        $at = Carbon::now();

        $ranked = Candidate::query()->ranked($story->id, $round)->get();
        /** @var Candidate|null $winner */
        $winner = $ranked->first();
        $votes = (int) $ranked->sum('votes_count');

        $line = null;
        if ($winner) {
            $line = new Line();
            $line->story_id = $story->id;
            $line->position = (int) Line::query()->where('story_id', $story->id)->max('position') + 1;
            $line->round = $round;
            $line->user_id = $winner->user_id;
            $line->body = $winner->body;
            $line->votes = (int) $winner->votes_count;
            $line->rivals = $ranked->count() - 1;
            $line->created_at = $at;
            $line->save();
        }

        $finished = $story->final_round || ($winner && $story->max_lines && $story->line_count + 1 >= $story->max_lines);

        $changes = ['updated_at' => $at];
        if ($winner) {
            $changes += [
                'round' => $round + 1,
                'line_count' => $story->line_count + 1,
                'candidate_count' => $story->candidate_count + $ranked->count(),
                'vote_count' => $story->vote_count + $votes,
                'contributor_count' => self::contributors($story->id),
                'last_line_at' => $at,
            ];
        }
        if ($finished) {
            $changes += ['status' => Story::FINISHED, 'finished_at' => $at, 'round_ends_at' => $at];
        } else {
            $changes['round_ends_at'] = $now ? $at->copy()->addHours($story->round_hours) : self::nextClose($story, $at);
        }

        // Only the closer that still sees this round open moves the story on.
        $moved = Story::query()->where('id', $story->id)->where('round', $round)->where('status', Story::ACTIVE)->update($changes);
        if ($moved !== 1) {
            throw new RaceLost();
        }

        return ['round' => $round, 'line' => $line?->id, 'finished' => $finished];
    }

    /** People credited with a shown line. */
    public static function contributors(int $storyId): int
    {
        return (int) Line::query()->where('story_id', $storyId)->shown()->whereNotNull('user_id')->distinct()->count('user_id');
    }

    /**
     * The next close keeps the story's rhythm (a daily round keeps closing at
     * the same time of day) unless the scheduler has been away for longer than
     * a round, when the new round gets its full length from now.
     */
    public static function nextClose(Story $story, Carbon $at): Carbon
    {
        $next = $story->round_ends_at->copy()->addHours($story->round_hours);

        return $next->lessThanOrEqualTo($at->copy()->addMinutes(10)) ? $at->copy()->addHours($story->round_hours) : $next;
    }

    private function isDuplicate(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? '');

        return $state === '23000' || $state === '23505' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
