<?php

namespace ErnestDefoe\StoryChain\Api;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\Closer;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Presenter;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/stories: stories being written (with their newest line and how
 * many lines are in the running) and the library of finished ones, a page
 * at a time. A fixed number of queries however many stories there are.
 * `?library=N` asks for the next page of the library only.
 */
class ListController implements RequestHandlerInterface
{
    private const ACTIVE = 50;
    private const PAGE = 24;

    public function __construct(private readonly Presenter $present, private readonly Closer $closer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $offset = max(0, (int) Arr::get($request->getQueryParams(), 'library', 0));
        $body = ['now' => Carbon::now()->toIso8601String()];
        $userIds = [];

        if ($offset === 0) {
            // Forums without a scheduler: close what ran out before showing it (a no-op when the scheduler keeps up).
            $due = Story::query()->where('status', Story::ACTIVE)->where('round_ends_at', '<=', Carbon::now())->limit(5)->pluck('id');
            foreach ($due as $id) {
                $this->closer->close((int) $id);
            }

            $active = Story::query()->where('status', Story::ACTIVE)->orderBy('round_ends_at')->limit(self::ACTIVE)->get();
            $ids = $active->modelKeys();

            $newest = $ids ? Line::query()->whereIn('id', Line::query()->toBase()->whereIn('story_id', $ids)->whereNull('hidden_at')->groupBy('story_id')->selectRaw('max(id)'))->get()->keyBy('story_id') : collect();
            // The open round's lines only: a join on the story's own round.
            $offered = $ids ? Candidate::query()->toBase()
                ->join('story_chain_stories', 'story_chain_stories.id', '=', 'story_chain_candidates.story_id')
                ->whereColumn('story_chain_candidates.round', 'story_chain_stories.round')
                ->whereIn('story_chain_candidates.story_id', $ids)->whereNull('story_chain_candidates.hidden_at')
                ->groupBy('story_chain_candidates.story_id')
                ->select('story_chain_candidates.story_id')->selectRaw('count(*) as n')
                ->pluck('n', 'story_id') : collect();

            $body['active'] = $active->map(function (Story $story) use ($newest, $offered, &$userIds) {
                /** @var Line|null $last */
                $last = $newest->get($story->id);
                $userIds[] = $last?->user_id;

                return $this->present->story($story) + [
                    'last' => $last ? ['body' => $last->body, 'user' => $last->user_id] : null,
                    'offered' => (int) $offered->get($story->id, 0),
                ];
            })->values();
            $body['canStart'] = $actor->can(Settings::START);
        }

        $library = Story::query()->where('status', Story::FINISHED)->orderByDesc('finished_at')->orderByDesc('id')->skip($offset)->take(self::PAGE + 1)->get();
        $body['library'] = $library->take(self::PAGE)->map(fn (Story $story) => $this->present->story($story))->values();
        $body['more'] = $library->count() > self::PAGE;
        $body['users'] = $this->present->users($userIds);

        return new JsonResponse($body);
    }
}
