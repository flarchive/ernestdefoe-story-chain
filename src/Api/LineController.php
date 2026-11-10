<?php

namespace ErnestDefoe\StoryChain\Api;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\CardCache;
use ErnestDefoe\StoryChain\Closer;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/stories/lines/{id} {hidden}: a moderator removes a line from the
 * story (or puts it back). The row stays, so positions never shift; the
 * story's line and contributor counts follow, and a story with an end gets
 * a round back for each removed line while it is still being written.
 */
class LineController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly ConnectionInterface $db, private readonly CardCache $card)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::MODERATE);
        $hidden = (bool) ($this->body($request)['hidden'] ?? true);

        $response = $this->db->transaction(function () use ($request, $actor, $hidden) {
            $line = Line::query()->find($this->id($request));
            if (! $line) {
                return Errors::make(404, 'line_not_found');
            }
            $story = Story::locked($line->story_id);
            if (! $story) {
                return Errors::make(404, 'story_not_found');
            }

            if (! $hidden && $line->body === '') {
                // Erased at its writer's request (GDPR): never back.
                return Errors::make(409, 'line_erased');
            }

            $line->hidden_at = $hidden ? Carbon::now() : null;
            $line->hidden_by = $hidden ? $actor->id : null;
            $line->save();

            $story->line_count = Line::query()->where('story_id', $story->id)->shown()->count();
            $story->contributor_count = Closer::contributors($story->id);
            $story->save();

            return new EmptyResponse(204);
        });
        $this->card->forget();

        return $response;
    }
}
