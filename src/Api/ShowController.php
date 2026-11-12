<?php

namespace ErnestDefoe\StoryChain\Api;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\Closer;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Presenter;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use ErnestDefoe\StoryChain\Vote;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/stories/{id}: the story, its lines, the open round and the
 * credits, in one answer. A round that ran out with no scheduler run since
 * is closed here first, through the same race-safe Closer.
 *
 * While a round is open, vote counts are only sent when the admin chose
 * live votes, and who offered a line only when the admin chose to show it
 * (moderators always see who): never in the payload "just hidden by CSS".
 */
class ShowController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(
        private readonly Presenter $present,
        private readonly Closer $closer,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $story = $this->story($request);
        if (! $story) {
            return Errors::make(404, 'story_not_found');
        }
        if ($story->isActive() && $story->round_ends_at->isPast() && $this->closer->close($story->id)) {
            $story = $story->fresh() ?? $story;
        }

        return new JsonResponse($this->payload($story, $actor));
    }

    /** @return array<string, mixed> */
    public function payload(Story $story, User $actor): array
    {
        $moderator = $actor->can(Settings::MODERATE);

        $lines = Line::query()->where('story_id', $story->id)->when(! $moderator, fn ($q) => $q->shown())->orderBy('position')->get();
        $userIds = $lines->pluck('user_id')->all();

        $round = null;
        if ($story->isActive()) {
            $round = $this->round($story, $actor, $moderator, $userIds);
        }

        $credits = Line::query()->toBase()->where('story_id', $story->id)->whereNull('hidden_at')->whereNotNull('user_id')
            ->groupBy('user_id')->selectRaw('user_id, count(*) as n')->get()
            ->map(fn ($row) => [(int) $row->user_id, (int) $row->n])
            ->sortBy([[1, 'desc'], [0, 'asc']])->values()->all();

        $userIds[] = $story->user_id;

        return [
            'story' => $this->present->story($story) + ['canModerate' => $moderator],
            'lines' => $lines->map(fn (Line $line) => [
                'id' => $line->id,
                'position' => $line->position,
                'round' => $line->round,
                'user' => $line->user_id,
                'body' => $line->body,
                'votes' => $line->votes,
                'rivals' => $line->rivals,
                'at' => $line->created_at->toIso8601String(),
                'hidden' => $line->hidden_at !== null,
            ])->values(),
            'round' => $round,
            'credits' => $credits,
            'users' => $this->present->users($userIds),
            'now' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * The open round as this viewer may see it.
     *
     * @param list<int|null> $userIds people to send along (added to)
     * @return array<string, mixed>
     */
    private function round(Story $story, User $actor, bool $moderator, array &$userIds): array
    {
        $live = (bool) $this->settings->get(Settings::LIVE_VOTES);
        $authors = $moderator || (bool) $this->settings->get(Settings::SHOW_AUTHORS);

        $all = Candidate::query()->where('story_id', $story->id)->where('round', $story->round)->withCount('votes')->get();
        $shown = $all->whereNull('hidden_at');
        // A member still sees their own line after a moderator removed it, marked as removed.
        $visible = $all->filter(fn (Candidate $c) => $c->hidden_at === null || $moderator || $c->user_id === $actor->id);

        if ($live) {
            $visible = $visible->sortBy([['votes_count', 'desc'], ['id', 'asc']]);
        } else {
            // No order to read the count from: a shuffle of its own for every viewer and round.
            $seed = $actor->id.':'.$story->round;
            $visible = $visible->sortBy(fn (Candidate $c) => crc32($c->id.':'.$seed));
        }

        $myVote = $actor->isGuest() ? null : Vote::query()->where('story_id', $story->id)->where('round', $story->round)->where('user_id', $actor->id)->value('candidate_id');

        $candidates = $visible->map(function (Candidate $c) use ($actor, $authors, $live, &$userIds) {
            $mine = $c->user_id === $actor->id;
            $row = ['id' => $c->id, 'body' => $c->body, 'mine' => $mine];
            if ($authors || $mine) {
                $row['user'] = $c->user_id;
                $userIds[] = $c->user_id;
            }
            if ($live) {
                $row['votes'] = (int) $c->votes_count;
            }
            if ($c->hidden_at) {
                $row['hidden'] = true;
            }

            return $row;
        })->values();

        return [
            'number' => $story->round,
            'open' => $story->isOpen(),
            'live' => $live,
            'authors' => $authors,
            'offered' => $shown->count(),
            'voters' => (int) $shown->sum('votes_count'),
            'candidates' => $candidates,
            'myVote' => $myVote ? (int) $myVote : null,
            'write' => $this->canWrite($story, $actor, $all->contains('user_id', $actor->id)),
            'canVote' => ! $actor->isGuest() && $actor->can(Settings::VOTE),
        ];
    }

    /** null when the viewer may offer a line now, else why not. */
    private function canWrite(Story $story, User $actor, bool $offered): ?string
    {
        if ($actor->isGuest()) {
            return 'guest';
        }
        if (! $actor->can(Settings::WRITE)) {
            return 'permission';
        }
        if (! $story->isOpen()) {
            return 'closed';
        }
        if ($offered) {
            return 'offered';
        }
        if ($this->settings->get(Settings::NO_REPEAT) && Offer::wroteLast($story, $actor)) {
            return 'repeat';
        }

        return null;
    }
}
