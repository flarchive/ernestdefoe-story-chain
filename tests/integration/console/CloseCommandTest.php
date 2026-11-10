<?php

namespace ErnestDefoe\StoryChain\Tests\integration\console;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Story;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class CloseCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-story-chain');
        $this->prepareDatabase([
            User::class => [['id' => 2, 'username' => 'normal', 'email' => 'normal@machine.local', 'is_email_confirmed' => 1]],
            Story::class => [['id' => 1, 'title' => 'Due', 'slug' => 'due', 'status' => 'active', 'round' => 1, 'round_ends_at' => Carbon::now()->subMinute(), 'round_hours' => 24, 'max_length' => 200, 'line_count' => 1, 'created_at' => Carbon::now()->subDay()]],
            Line::class => [['id' => 1, 'story_id' => 1, 'position' => 1, 'round' => 0, 'user_id' => 1, 'body' => 'Once.', 'created_at' => Carbon::now()->subDay()]],
            Candidate::class => [['id' => 1, 'story_id' => 1, 'round' => 1, 'user_id' => 2, 'body' => 'Then.', 'created_at' => Carbon::now()->subHour()]],
        ]);
    }

    #[Test]
    public function the_scheduler_closes_rounds_every_minute(): void
    {
        $this->assertStringContainsString('story-chain:close', $this->runCommand(['command' => 'schedule:list']));

        $this->runCommand(['command' => 'story-chain:close']);
        $this->runCommand(['command' => 'story-chain:close']);

        $this->assertSame(2, Story::query()->find(1)->round);
        $this->assertSame(['Once.', 'Then.'], Line::query()->orderBy('position')->pluck('body')->all());
    }
}
