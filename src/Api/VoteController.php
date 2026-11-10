<?php

namespace ErnestDefoe\StoryChain\Api;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use ErnestDefoe\StoryChain\Vote;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/stories/{id}/vote {candidate: id|null}: give your one vote for
 * the round to a line (not your own), move it, or take it back. Runs with
 * the story locked, so a vote can't land after its round closed.
 */
class VoteController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly ConnectionInterface $db, private readonly RateLimit $limits)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);
        $actor->assertCan(Settings::VOTE);

        if ($this->limits->exceeded($actor, 'vote', 30)) {
            return Errors::make(429, 'too_fast');
        }

        $candidateId = (int) ($this->body($request)['candidate'] ?? 0);

        return $this->db->transaction(function () use ($request, $actor, $candidateId) {
            $story = Story::locked($this->id($request));
            if (! $story) {
                return Errors::make(404, 'story_not_found');
            }
            if (! $story->isOpen()) {
                return Errors::make(409, 'round_closed');
            }

            $candidate = null;
            if ($candidateId) {
                $candidate = Candidate::query()->where('story_id', $story->id)->where('round', $story->round)->shown()->find($candidateId);
                if (! $candidate) {
                    return Errors::make(404, 'line_not_found');
                }
                if ($candidate->user_id === $actor->id) {
                    return Errors::make(409, 'own_line');
                }
            }

            Vote::query()->where('story_id', $story->id)->where('round', $story->round)->where('user_id', $actor->id)->delete();

            if ($candidate) {
                $vote = new Vote();
                $vote->story_id = $story->id;
                $vote->round = $story->round;
                $vote->candidate_id = $candidate->id;
                $vote->user_id = $actor->id;
                $vote->created_at = Carbon::now();
                try {
                    $this->db->transaction(fn () => $vote->save());
                } catch (QueryException $e) {
                    // Two taps at once: the other one's vote stands.
                    if (! self::isDuplicate($e)) {
                        throw $e;
                    }
                }
            }

            return new JsonResponse(['vote' => $candidate?->id]);
        });
    }
}
