<?php

namespace ErnestDefoe\StoryChain\Api;

use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\CardCache;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Vote;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** DELETE /api/stories/{id}: a moderator deletes a story and everything in it. */
class DeleteController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly ConnectionInterface $db, private readonly CardCache $card)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::MODERATE);

        $story = $this->story($request);
        if (! $story) {
            return Errors::make(404, 'story_not_found');
        }

        // The foreign keys do this too, where the database enforces them (SQLite may not).
        $this->db->transaction(function () use ($story) {
            Vote::query()->where('story_id', $story->id)->delete();
            Candidate::query()->where('story_id', $story->id)->delete();
            Line::query()->where('story_id', $story->id)->delete();
            $story->delete();
        });
        $this->card->forget();

        return new EmptyResponse(204);
    }
}
