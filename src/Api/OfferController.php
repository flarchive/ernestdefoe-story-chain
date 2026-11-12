<?php

namespace ErnestDefoe\StoryChain\Api;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\CardCache;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use ErnestDefoe\StoryChain\Text;
use ErnestDefoe\StoryChain\Vote;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A member's line for the open round:
 *
 *   POST   /api/stories/{id}/lines       offer one (one per member per round)
 *   DELETE /api/stories/candidates/{id}  take your own back while the round is open
 *   POST   /api/stories/candidates/{id}  {hidden} a moderator removes (or restores) one
 *
 * Every change runs with the story row locked, so it can't land in a round
 * the Closer is closing; the round is re-checked inside the lock.
 */
class OfferController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settings,
        private readonly RateLimit $limits,
        private readonly CardCache $card,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);

        $route = (string) $request->getAttribute('routeName');

        $response = match ($route) {
            'story-chain.lines.offer' => $this->offer($request, $actor),
            'story-chain.candidates.withdraw' => $this->withdraw($request, $actor),
            default => $this->moderate($request, $actor),
        };
        $this->card->forget();

        return $response;
    }

    private function offer(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertCan(Settings::WRITE);
        if ($this->limits->exceeded($actor, 'offer', 6)) {
            return Errors::make(429, 'too_fast');
        }

        $body = Text::clean($this->body($request)['body'] ?? null);

        return $this->db->transaction(function () use ($request, $actor, $body) {
            $story = Story::locked($this->id($request));
            if (! $story) {
                return Errors::make(404, 'story_not_found');
            }
            if (! $story->isOpen()) {
                return Errors::make(409, 'round_closed');
            }
            if (Text::length($body) < 2) {
                return Errors::make(422, 'line_empty');
            }
            if (Text::length($body) > $story->max_length) {
                return Errors::make(422, 'line_too_long');
            }
            if (Candidate::query()->where('story_id', $story->id)->where('round', $story->round)->where('user_id', $actor->id)->exists()) {
                return Errors::make(409, 'already_offered');
            }
            if ($this->settings->get(Settings::NO_REPEAT) && Offer::wroteLast($story, $actor)) {
                return Errors::make(409, 'wrote_last');
            }

            $candidate = new Candidate();
            $candidate->story_id = $story->id;
            $candidate->round = $story->round;
            $candidate->user_id = $actor->id;
            $candidate->body = $body;
            $candidate->created_at = Carbon::now();

            try {
                // A savepoint, so a double tap's duplicate doesn't poison the outer transaction (PostgreSQL).
                $this->db->transaction(fn () => $candidate->save());
            } catch (QueryException $e) {
                if (self::isDuplicate($e)) {
                    return Errors::make(409, 'already_offered');
                }
                throw $e;
            }

            return new JsonResponse(['id' => $candidate->id], 201);
        });
    }

    private function withdraw(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        return $this->db->transaction(function () use ($request, $actor) {
            $candidate = Candidate::query()->find($this->id($request));
            if (! $candidate || $candidate->user_id !== $actor->id) {
                return Errors::make(404, 'line_not_found');
            }
            $story = Story::locked($candidate->story_id);
            if (! $story || ! $story->isOpen() || $story->round !== $candidate->round) {
                return Errors::make(409, 'round_closed');
            }
            if ($candidate->hidden_at) {
                // Taking back a removed line would let its writer offer it again.
                return Errors::make(409, 'line_removed');
            }

            Vote::query()->where('candidate_id', $candidate->id)->delete();
            $candidate->delete();

            return new EmptyResponse(204);
        });
    }

    private function moderate(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertCan(Settings::MODERATE);
        $hidden = (bool) ($this->body($request)['hidden'] ?? true);

        return $this->db->transaction(function () use ($request, $actor, $hidden) {
            $candidate = Candidate::query()->find($this->id($request));
            if (! $candidate) {
                return Errors::make(404, 'line_not_found');
            }
            $story = Story::locked($candidate->story_id);
            if (! $story || ! $story->isOpen() || $story->round !== $candidate->round) {
                return Errors::make(409, 'round_closed');
            }

            if ($hidden) {
                $candidate->hidden_at = Carbon::now();
                $candidate->hidden_by = $actor->id;
                // Its voters get their vote back to give to another line.
                Vote::query()->where('candidate_id', $candidate->id)->delete();
            } else {
                $candidate->hidden_at = null;
                $candidate->hidden_by = null;
            }
            $candidate->save();

            return new EmptyResponse(204);
        });
    }
}
