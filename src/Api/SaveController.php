<?php

namespace ErnestDefoe\StoryChain\Api;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\CardCache;
use ErnestDefoe\StoryChain\Closer;
use ErnestDefoe\StoryChain\Job\SendRoundNews;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use ErnestDefoe\StoryChain\Text;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST  /api/stories        start a story from its opening line (storyChain.start)
 * PATCH /api/stories/{id}   a moderator edits it, makes the open round the
 *                           last, closes the round now, or finishes the story
 *
 * Only the fields named here are read; counters, rounds and status are the
 * Closer's alone (apart from "finish", which ends a story without a line).
 */
class SaveController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Closer $closer,
        private readonly RateLimit $limits,
        private readonly CardCache $card,
        private readonly Queue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);

        $response = $request->getMethod() === 'POST' ? $this->create($request, $actor) : $this->update($request, $actor);
        $this->card->forget();

        return $response;
    }

    private function create(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertCan(Settings::START);
        if (! $actor->can(Settings::MODERATE) && ($this->limits->exceeded($actor, 'start', 2) || $this->limits->dailyExceeded($actor, 'start', 3))) {
            return Errors::make(429, 'too_many_stories');
        }

        $input = $this->body($request);
        $story = new Story();
        $story->user_id = $actor->id;
        $story->status = Story::ACTIVE;
        $story->round = 1;
        $story->round_hours = 24;
        $story->max_length = 200;
        $story->cover = Settings::COVERS[0];

        if ($error = $this->fill($story, $input)) {
            return $error;
        }

        $opening = Text::clean($input['opening'] ?? null);
        if (Text::length($opening) < 2) {
            return Errors::make(422, 'opening_empty');
        }
        if (Text::length($opening) > Settings::MAX_LENGTH) {
            return Errors::make(422, 'line_too_long');
        }

        $now = Carbon::now();
        $story->round_ends_at = $now->copy()->addHours($story->round_hours);
        $story->line_count = 1;
        $story->contributor_count = 1;
        $story->last_line_at = $now;

        $this->db->transaction(function () use ($story, $opening, $actor, $now) {
            $story->save();

            $line = new Line();
            $line->story_id = $story->id;
            $line->position = 1;
            $line->round = 0;
            $line->user_id = $actor->id;
            $line->body = $opening;
            $line->created_at = $now;
            $line->save();
        });

        return new JsonResponse(['id' => $story->id, 'slug' => $story->slug], 201);
    }

    private function update(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertCan(Settings::MODERATE);
        $input = $this->body($request);
        $action = $input['action'] ?? null;

        if ($action === 'close') {
            $story = $this->story($request);
            if (! $story) {
                return Errors::make(404, 'story_not_found');
            }

            return $this->closer->close($story->id, true) ? new JsonResponse(['closed' => true]) : Errors::make(409, 'round_closed');
        }

        $finished = null;
        $response = $this->db->transaction(function () use ($request, $input, $action, &$finished) {
            $story = Story::locked($this->id($request));
            if (! $story) {
                return Errors::make(404, 'story_not_found');
            }

            if ($error = $this->fill($story, $input)) {
                return $error;
            }

            if (array_key_exists('finalRound', $input) && $story->isActive()) {
                $story->final_round = (bool) $input['finalRound'];
            }

            if ($action === 'finish' && $story->isActive()) {
                $now = Carbon::now();
                $story->status = Story::FINISHED;
                $story->finished_at = $now;
                $story->round_ends_at = $now;
                $finished = $story->round;
            }

            $story->save();

            return new JsonResponse(['id' => $story->id, 'slug' => $story->slug]);
        });

        // Everyone taking part hears it finished, after the commit.
        if ($finished !== null) {
            $this->queue->push(new SendRoundNews($this->id($request), $finished, null, true));
        }

        return $response;
    }

    /**
     * The editable fields, checked. A new round length applies from the next
     * round; the open round keeps the close time its writers were promised.
     *
     * @param array<string, mixed> $input
     */
    private function fill(Story $story, array $input): ?ResponseInterface
    {
        $creating = ! $story->exists;

        if ($creating || array_key_exists('title', $input)) {
            $title = Text::clean($input['title'] ?? null);
            if (Text::length($title) < 2 || Text::length($title) > 120) {
                return Errors::make(422, 'title_invalid');
            }
            $story->title = $title;
            $story->slug = Str::slug($title) ?: 'story';
        }

        if (array_key_exists('blurb', $input)) {
            $blurb = Text::clean($input['blurb']);
            $story->blurb = $blurb === '' ? null : mb_substr($blurb, 0, 300);
        }

        if (array_key_exists('cover', $input)) {
            if (! in_array($input['cover'], Settings::COVERS, true)) {
                return Errors::make(422, 'cover_invalid');
            }
            $story->cover = $input['cover'];
        }

        if (array_key_exists('roundHours', $input)) {
            if (! in_array((int) $input['roundHours'], Settings::ROUND_HOURS, true)) {
                return Errors::make(422, 'hours_invalid');
            }
            $story->round_hours = (int) $input['roundHours'];
        }

        if (array_key_exists('maxLength', $input)) {
            $length = (int) $input['maxLength'];
            if ($length < Settings::MIN_LENGTH || $length > Settings::MAX_LENGTH) {
                return Errors::make(422, 'length_invalid');
            }
            $story->max_length = $length;
        }

        if (array_key_exists('maxLines', $input)) {
            $lines = $input['maxLines'] === null || $input['maxLines'] === '' ? null : (int) $input['maxLines'];
            // An end the story has already passed would finish it at the next close, which is what "make this the last round" is for.
            if ($lines !== null && ($lines < 3 || $lines > Settings::MAX_LINES || (! $creating && $lines <= $story->line_count))) {
                return Errors::make(422, 'lines_invalid');
            }
            $story->max_lines = $lines;
        }

        return null;
    }
}
