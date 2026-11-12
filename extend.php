<?php

/*
 * Story Chain: the forum writes a story together, one line at a time.
 *
 * Every round, members each offer a next line and vote for one; when the
 * round closes the most-voted line (ties: the earliest offered) joins the
 * story, credited to its writer. The scheduler closes rounds every minute
 * (Closer: race-safe and idempotent), and the pages close any round they
 * show that ran out, for forums whose scheduler isn't running. Finished
 * stories become chapters in the library.
 *
 * Stories are served by our plain endpoints; forum.js only wires up the
 * routes, the nav link, the index card and the notifications. The pages
 * and forms are lazy chunks.
 */

use ErnestDefoe\StoryChain\Api;
use ErnestDefoe\StoryChain\CardCache;
use ErnestDefoe\StoryChain\Console\CloseCommand;
use ErnestDefoe\StoryChain\Forget;
use ErnestDefoe\StoryChain\Gdpr\StoryChainData;
use ErnestDefoe\StoryChain\Http\PageContent;
use ErnestDefoe\StoryChain\Notification\LineWonBlueprint;
use ErnestDefoe\StoryChain\Notification\RoundBlueprint;
use ErnestDefoe\StoryChain\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Deleting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the pages and forms are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/stories', 'story-chain', PageContent::class)
        ->route('/stories/{id:\d+(?:-[^/]*)?}', 'story-chain.story', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-story-chain', __DIR__.'/views'),

    (new Extend\Routes('api'))
        ->get('/stories', 'story-chain.index', Api\ListController::class)
        ->post('/stories', 'story-chain.create', Api\SaveController::class)
        ->get('/stories/{id:\d+}', 'story-chain.show', Api\ShowController::class)
        ->patch('/stories/{id:\d+}', 'story-chain.update', Api\SaveController::class)
        ->delete('/stories/{id:\d+}', 'story-chain.delete', Api\DeleteController::class)
        ->post('/stories/{id:\d+}/lines', 'story-chain.lines.offer', Api\OfferController::class)
        ->post('/stories/{id:\d+}/vote', 'story-chain.vote', Api\VoteController::class)
        ->delete('/stories/candidates/{id:\d+}', 'story-chain.candidates.withdraw', Api\OfferController::class)
        ->post('/stories/candidates/{id:\d+}', 'story-chain.candidates.moderate', Api\OfferController::class)
        ->post('/stories/lines/{id:\d+}', 'story-chain.lines.moderate', Api\LineController::class),

    (new Extend\Console())
        ->command(CloseCommand::class)
        ->schedule(CloseCommand::class, function (ScheduledEvent $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),

    (new Extend\Notification())
        ->type(LineWonBlueprint::class, ['alert', 'email'])
        ->type(RoundBlueprint::class, ['alert']),

    // The viewer's own permissions (already loaded) and the cached card: no query on a normal page.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('storyChainCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
            Schema\Arr::make('storyChainCard')
                ->visible(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW) && resolve(SettingsRepositoryInterface::class)->get(Settings::CARD))
                ->get(fn () => resolve(CardCache::class)->get()),
        ]),

    (new Extend\Settings())
        ->default(Settings::NAV, true)
        ->default(Settings::CARD, true)
        ->default(Settings::LIVE_VOTES, false)
        ->default(Settings::SHOW_AUTHORS, false)
        ->default(Settings::NO_REPEAT, true)
        ->serializeToForum('storyChainNav', Settings::NAV, 'boolval'),

    // Before the user row goes: recount the stories they wrote in (the foreign keys would only null their name).
    (new Extend\Event())
        ->listen(Deleting::class, fn (Deleting $event) => Forget::user($event->user, false)),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(StoryChainData::class),
        ]),
];
