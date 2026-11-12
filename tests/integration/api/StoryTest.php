<?php

namespace ErnestDefoe\StoryChain\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\Closer;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use ErnestDefoe\StoryChain\Vote;
use Flarum\Group\Group;
use Flarum\Notification\Notification;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

class StoryTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-story-chain');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'sam', 'email' => 'sam@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'lee', 'email' => 'lee@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $response = $this->send($this->request($method, '/api/stories'.$path, $options));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function put(string $key, mixed $value): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set($key, $value);
    }

    /** A story started by the admin; returns its id. */
    private function start(array $overrides = []): int
    {
        [$status, $body] = $this->api('POST', '', 1, $overrides + ['title' => 'The Lighthouse', 'opening' => 'The lamp came on by itself.', 'maxLines' => 5]);
        $this->assertSame(201, $status, json_encode($body));

        return $body['id'];
    }

    private function offer(int $story, int $as, string $text): int
    {
        [$status, $body] = $this->api('POST', "/$story/lines", $as, ['body' => $text]);
        $this->assertSame(201, $status, json_encode($body));

        return $body['id'];
    }

    private function vote(int $story, int $as, ?int $candidate): int
    {
        return $this->api('POST', "/$story/vote", $as, ['candidate' => $candidate])[0];
    }

    private function runOut(int $story): void
    {
        Story::query()->where('id', $story)->update(['round_ends_at' => Carbon::now()->subMinute()]);
    }

    private function closer(): Closer
    {
        return $this->app()->getContainer()->make(Closer::class);
    }

    #[Test]
    public function only_starters_start_stories_and_the_opening_line_is_line_one(): void
    {
        $this->app();
        $this->assertSame(403, $this->api('POST', '', 2, ['title' => 'Mine', 'opening' => 'Once.'])[0]);
        $this->assertContains($this->api('POST', '', null, ['title' => 'Mine', 'opening' => 'Once.'])[0], [400, 401]);

        $id = $this->start();
        [$status, $body] = $this->api('GET', "/$id", null);
        $this->assertSame(200, $status);
        $this->assertSame('The lamp came on by itself.', $body['lines'][0]['body']);
        $this->assertSame(1, $body['story']['round']);
        $this->assertSame('guest', $body['round']['write']);
    }

    #[Test]
    public function one_line_per_member_per_round_within_the_length(): void
    {
        $id = $this->start(['maxLength' => 40]);
        $this->offer($id, 2, "A gull\n landed   on the rail.");
        $this->assertSame('A gull landed on the rail.', Candidate::query()->value('body'));

        [$status, $body] = $this->api('POST', "/$id/lines", 2, ['body' => 'Another try.']);
        $this->assertSame([409, 'already_offered'], [$status, $body['errors'][0]['code']]);

        [$status, $body] = $this->api('POST', "/$id/lines", 3, ['body' => str_repeat('x', 41)]);
        $this->assertSame([422, 'line_too_long'], [$status, $body['errors'][0]['code']]);

        // The admin wrote the newest line (the opening), so they sit this round out.
        [$status, $body] = $this->api('POST', "/$id/lines", 1, ['body' => 'Me again.']);
        $this->assertSame([409, 'wrote_last'], [$status, $body['errors'][0]['code']]);
    }

    #[Test]
    public function votes_and_writers_stay_out_of_the_payload_until_the_admin_allows_them(): void
    {
        $id = $this->start();
        $a = $this->offer($id, 2, 'Line from Normal.');
        $this->offer($id, 3, 'Line from Dana.');
        $this->assertSame(200, $this->vote($id, 4, $a));

        [, $member] = $this->api('GET', "/$id", 5);
        foreach ($member['round']['candidates'] as $c) {
            $this->assertArrayNotHasKey('votes', $c);
            $this->assertArrayNotHasKey('user', $c);
        }
        $this->assertSame(1, $member['round']['voters']);

        // Moderators see who wrote what, never the counts.
        [, $admin] = $this->api('GET', "/$id", 1);
        $this->assertArrayHasKey('user', $admin['round']['candidates'][0]);
        $this->assertArrayNotHasKey('votes', $admin['round']['candidates'][0]);

        $this->put(Settings::LIVE_VOTES, true);
        $this->put(Settings::SHOW_AUTHORS, true);
        [, $live] = $this->api('GET', "/$id", 5);
        $this->assertSame([1, 0], array_column($live['round']['candidates'], 'votes'));
        $this->assertSame(2, $live['round']['candidates'][0]['user']);
    }

    #[Test]
    public function one_vote_per_round_never_for_your_own_line_and_only_while_open(): void
    {
        $id = $this->start();
        $a = $this->offer($id, 2, 'First.');
        $b = $this->offer($id, 3, 'Second.');

        $this->assertSame(409, $this->vote($id, 2, $a));
        $this->assertSame(200, $this->vote($id, 4, $a));
        $this->assertSame(200, $this->vote($id, 4, $b));
        $this->assertSame([$b], Vote::query()->where('user_id', 4)->pluck('candidate_id')->all());
        $this->assertSame(200, $this->vote($id, 4, null));
        $this->assertSame(0, Vote::query()->count());

        $this->assertContains($this->vote($id, 0, $a), [400, 401]);

        $this->runOut($id);
        Story::query()->where('id', $id)->update(['round_ends_at' => Carbon::now()->subMinute()]);
        // Straight to the endpoint (no page load to close it first): refused.
        $this->assertSame(409, $this->vote($id, 4, $a));
    }

    #[Test]
    public function the_most_voted_line_wins_and_a_tie_goes_to_the_earliest(): void
    {
        $id = $this->start();
        $early = $this->offer($id, 2, 'Early bird.');
        $late = $this->offer($id, 3, 'Late bird.');
        $this->vote($id, 4, $late);
        $this->vote($id, 5, $early);

        $this->runOut($id);
        $this->assertTrue($this->closer()->close($id));

        $line = Line::query()->where('story_id', $id)->orderByDesc('position')->first();
        $this->assertSame(['Early bird.', 2, 1, 1, 1], [$line->body, $line->user_id, $line->votes, $line->rivals, $line->round]);

        $story = Story::query()->find($id);
        $this->assertSame([2, 2, 2, 2, 2], [$story->round, $story->line_count, $story->contributor_count, $story->candidate_count, $story->vote_count]);
        $this->assertTrue($story->round_ends_at->isFuture());

        // Round 2: more votes beat an earlier line.
        $first = $this->offer($id, 3, 'First in.');
        $popular = $this->offer($id, 4, 'Popular.');
        $this->vote($id, 5, $popular);
        $this->runOut($id);
        $this->closer()->close($id);
        $this->assertSame('Popular.', Line::query()->where('story_id', $id)->orderByDesc('position')->value('body'));
        $this->assertNotNull($first);
    }

    #[Test]
    public function closing_is_idempotent_and_a_racing_closer_adds_nothing(): void
    {
        $id = $this->start();
        $this->offer($id, 2, 'Only line.');
        $this->runOut($id);

        $this->assertSame(1, $this->closer()->run());
        $this->assertSame(0, $this->closer()->run());
        $this->assertFalse($this->closer()->close($id));
        $this->assertSame(2, Line::query()->where('story_id', $id)->count());

        // A closer that read round 1 before the other moved on: the line for round 1 already exists.
        $story = Story::query()->find($id);
        Story::query()->where('id', $id)->update(['round' => 1, 'round_ends_at' => Carbon::now()->subMinute()]);
        Candidate::query()->update(['round' => 1]);
        $this->assertFalse($this->closer()->close($id));
        $this->assertSame(2, Line::query()->where('story_id', $id)->count());
        $this->assertNotNull($story);
    }

    #[Test]
    public function a_round_with_no_lines_stays_open_for_another_round(): void
    {
        $id = $this->start();
        $this->runOut($id);
        $this->assertTrue($this->closer()->close($id));

        $story = Story::query()->find($id);
        $this->assertSame([1, 1, 'active'], [$story->round, $story->line_count, $story->status]);
        $this->assertTrue($story->round_ends_at->isFuture());
    }

    #[Test]
    public function a_story_finishes_at_its_length_or_after_the_final_round(): void
    {
        $id = $this->start(['maxLines' => 3]);
        foreach ([2, 3] as $writer) {
            $this->offer($id, $writer, "Line by $writer.");
            $this->runOut($id);
            $this->closer()->close($id);
        }
        $story = Story::query()->find($id);
        $this->assertSame(['finished', 3], [$story->status, $story->line_count]);
        $this->assertSame(409, $this->api('POST', "/$id/lines", 4, ['body' => 'Too late.'])[0]);

        $other = $this->start(['title' => 'Open ended', 'maxLines' => null]);
        $this->assertSame(403, $this->api('PATCH', "/$other", 2, ['finalRound' => true])[0]);
        $this->assertSame(200, $this->api('PATCH', "/$other", 1, ['finalRound' => true])[0]);
        $this->offer($other, 2, 'The end.');
        $this->runOut($other);
        $this->closer()->close($other);
        $this->assertSame('finished', Story::query()->find($other)->status);
    }

    #[Test]
    public function the_winner_and_everyone_taking_part_are_told_once(): void
    {
        $id = $this->start();
        $a = $this->offer($id, 2, 'Winner.');
        $this->offer($id, 3, 'Runner-up.');
        $this->vote($id, 4, $a);

        $this->runOut($id);
        $this->closer()->close($id);
        $this->closer()->close($id);

        $this->assertSame([2], Notification::query()->where('type', 'storyChainWon')->pluck('user_id')->all());
        $told = Notification::query()->where('type', 'storyChainRound')->pluck('user_id')->sort()->values()->all();
        // The admin (opening line), Dana (offered) and Sam (voted); never the winner, never Lee (not taking part).
        $this->assertSame([1, 3, 4], $told);

        [$status, $body] = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 3]))->getStatusCode() === 200 ? [200, null] : [500, null];
        $this->assertSame(200, $status);
        $this->assertNull($body);
    }

    #[Test]
    public function members_take_back_only_their_own_lines_and_moderators_remove_any(): void
    {
        $id = $this->start();
        $a = $this->offer($id, 2, 'Mine.');
        $b = $this->offer($id, 3, 'Dana here.');
        $this->vote($id, 4, $b);

        $this->assertSame(404, $this->api('DELETE', "/candidates/$b", 2)[0]);
        $this->assertSame(204, $this->api('DELETE', "/candidates/$a", 2)[0]);
        $this->offer($id, 2, 'Mine, again.');

        $this->assertSame(403, $this->api('POST', "/candidates/$b", 2, ['hidden' => true])[0]);
        $this->assertSame(204, $this->api('POST', "/candidates/$b", 1, ['hidden' => true])[0]);
        $this->assertSame(0, Vote::query()->count());
        $this->assertSame(409, $this->vote($id, 4, $b) === 404 ? 409 : 0);

        // Its writer can't take a removed line back and offer it again.
        [$status, $body] = $this->api('DELETE', "/candidates/$b", 3);
        $this->assertSame([409, 'line_removed'], [$status, $body['errors'][0]['code']]);
        [, $view] = $this->api('GET', "/$id", 4);
        $this->assertNotContains('Dana here.', array_column($view['round']['candidates'], 'body'));

        // A line in the story: hidden from readers, counts follow.
        $line = Line::query()->where('story_id', $id)->value('id');
        $this->assertSame(403, $this->api('POST', "/lines/$line", 2, ['hidden' => true])[0]);
        $this->assertSame(204, $this->api('POST', "/lines/$line", 1, ['hidden' => true])[0]);
        [, $view] = $this->api('GET', "/$id", 4);
        $this->assertSame([], $view['lines']);
        $this->assertSame([0, 0], [Story::query()->find($id)->line_count, Story::query()->find($id)->contributor_count]);
    }

    #[Test]
    public function members_given_the_permission_may_start_a_few_stories_a_day(): void
    {
        $this->prepareDatabase(['group_permission' => [['permission' => Settings::START, 'group_id' => Group::MEMBER_ID]]]);
        $this->app();
        $codes = [];
        foreach (range(1, 4) as $i) {
            $codes[] = $this->api('POST', '', 2, ['title' => "Story $i", 'opening' => 'Once upon a time.'])[0];
        }
        $this->assertSame([201, 201, 429, 429], $codes);
    }

    #[Test]
    public function the_list_and_the_card_cost_a_fixed_number_of_queries(): void
    {
        $this->start();
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $count = function () use ($db): int {
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->api('GET', '', 2);
            // Ours only: core's session and last-seen writes come and go between requests.
            $n = count(array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'story_chain') || str_contains($q, 'users" where "id" in')));
            $db->disableQueryLog();

            return $n;
        };
        $one = $count();
        foreach (range(2, 12) as $i) {
            $this->start(['title' => "Story $i"]);
        }
        $this->assertSame($one, $count());
    }

    #[Test]
    public function generated_index_and_key_names_fit_mysql_with_a_nine_character_prefix(): void
    {
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $prefix = $db->getTablePrefix();
        $schema = $db->getSchemaBuilder();
        $names = [];
        foreach (['story_chain_stories', 'story_chain_lines', 'story_chain_candidates', 'story_chain_votes'] as $table) {
            foreach ($schema->getIndexes($table) as $index) {
                if ($index['primary'] ?? false) {
                    continue;
                }
                // Laravel's name with this test's prefix swapped for a nine-character one.
                $names[] = '123456789'.substr($index['name'], strlen($prefix));
                // Never a hand-made name: those aren't prefixed, so two forums in one database would collide.
                $this->assertStringStartsWith($prefix.$table.'_', $index['name']);
            }
            foreach ($schema->getForeignKeys($table) as $key) {
                $names[] = '123456789'.$table.'_'.implode('_', $key['columns']).'_foreign';
            }
        }
        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }
    }

    #[Test]
    public function erasing_a_member_takes_their_lines_out_and_anonymizing_keeps_them_nameless(): void
    {
        $this->extension('flarum-gdpr', 'ernestdefoe-story-chain');
        $id = $this->start();
        foreach ([2, 3] as $writer) {
            $this->offer($id, $writer, "Line by $writer.");
            $this->vote($id, 4, Candidate::query()->where('user_id', $writer)->value('id'));
            $this->runOut($id);
            $this->closer()->close($id);
        }
        $c = $this->app()->getContainer();
        $make = fn (int $user) => new \ErnestDefoe\StoryChain\Gdpr\StoryChainData(
            User::query()->find($user), null, $c->make(\Illuminate\Contracts\Filesystem\Factory::class),
            $c->make(SettingsRepositoryInterface::class), $c->make(\Flarum\Http\UrlGenerator::class), $c->make(\Flarum\Locale\TranslatorInterface::class)
        );

        $this->assertStringContainsString('Line by 2.', json_encode($make(2)->export()));
        $make(2)->delete();
        $make(3)->anonymize();

        $lines = Line::query()->where('story_id', $id)->orderBy('position')->get();
        $this->assertNotNull($lines[1]->hidden_at);
        $this->assertSame('', $lines[1]->body);
        $this->assertSame('Line by 3.', $lines[2]->body);
        // An erased line can't be put back by a moderator.
        [$status, $body] = $this->api('POST', '/lines/'.$lines[1]->id, 1, ['hidden' => false]);
        $this->assertSame([409, 'line_erased'], [$status, $body['errors'][0]['code']]);
        $this->assertNull($lines[1]->user_id);
        $this->assertNull($lines[2]->hidden_at);
        $this->assertNull($lines[2]->user_id);
        $this->assertSame([2, 1], [Story::query()->find($id)->line_count, Story::query()->find($id)->contributor_count]);
        $this->assertSame(0, Candidate::query()->whereIn('user_id', [2, 3])->count());
    }

    #[Test]
    public function a_moderator_finishing_a_story_tells_everyone_taking_part_the_starter_included(): void
    {
        $id = $this->start();
        $a = $this->offer($id, 2, 'Never added.');
        $this->vote($id, 3, $a);

        $this->assertSame(200, $this->api('PATCH', "/$id", 1, ['action' => 'finish'])[0]);

        $this->assertSame('finished', Story::query()->find($id)->status);
        $this->assertSame(0, Notification::query()->where('type', 'storyChainWon')->count());
        $told = Notification::query()->where('type', 'storyChainRound')->orderBy('user_id')->get();
        $this->assertSame([1, 2, 3], $told->pluck('user_id')->all());
        $this->assertTrue($told->every(fn ($n) => $n->from_user_id === null && $n->subject_id === $n->user_id));
        $this->assertTrue((bool) json_decode(json_encode($told->first()->data), true)['finished']);

        // They show in the recipient's list (core hides notifications whose subject is missing).
        $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 3]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('storyChainRound', (string) $response->getBody());
    }

    #[Test]
    public function deleting_an_account_recounts_the_stories_they_wrote_in(): void
    {
        $id = $this->start(['maxLines' => 3]);
        foreach ([3 => 'A middle line.', 2 => 'The last line.'] as $writer => $text) {
            $this->offer($id, $writer, $text);
            $this->runOut($id);
            $this->closer()->close($id);
        }
        $this->assertSame(['finished', 3], [Story::query()->find($id)->status, Story::query()->find($id)->contributor_count]);

        $this->assertSame(204, $this->send($this->request('DELETE', '/api/users/2', ['authenticatedAs' => 1]))->getStatusCode());

        $story = Story::query()->find($id);
        $this->assertSame([2, 3], [$story->contributor_count, $story->line_count]);
        $this->assertSame('The last line.', Line::query()->where('story_id', $id)->orderByDesc('position')->value('body'));
    }

    #[Test]
    public function the_list_counts_only_the_open_rounds_lines(): void
    {
        $id = $this->start();
        $this->offer($id, 2, 'Round one.');
        $this->offer($id, 3, 'Also round one.');
        $this->runOut($id);
        $this->closer()->close($id);
        $this->offer($id, 4, 'Round two.');

        [, $body] = $this->api('GET', '', 5);
        $this->assertSame(1, $body['active'][0]['offered']);
    }

    #[Test]
    public function a_member_starter_fixes_the_opening_line_until_the_first_round_closes(): void
    {
        $this->prepareDatabase(['group_permission' => [['permission' => Settings::START, 'group_id' => Group::MEMBER_ID]]]);
        $this->app();
        [, $body] = $this->api('POST', '', 2, ['title' => 'Typos', 'opening' => 'Teh lamp came on.']);
        $id = $body['id'];
        $opening = Line::query()->where('story_id', $id)->value('id');

        // Nobody else, and nothing empty or too long.
        $this->assertSame([403, 'line_locked'], $this->code('POST', "/lines/$opening", 3, ['body' => 'Mine now.']));
        $this->assertSame([422, 'line_empty'], $this->code('POST', "/lines/$opening", 2, ['body' => ' ']));
        $this->assertSame([422, 'line_too_long'], $this->code('POST', "/lines/$opening", 2, ['body' => str_repeat('a', 501)]));

        // The opening line was never voted on, so its starter may rewrite it freely.
        $this->assertSame(204, $this->api('POST', "/lines/$opening", 2, ['body' => "The lamp\n came on by itself, at noon."])[0]);
        $this->assertSame('The lamp came on by itself, at noon.', Line::query()->find($opening)->body);

        // A member can't use the moderators' Edit Story to do it.
        $this->assertSame(403, $this->api('PATCH', "/$id", 2, ['opening' => 'Sneaky.'])[0]);

        // Once the first round closes, it's settled.
        $this->offer($id, 3, 'A gull landed on the rail.');
        $this->runOut($id);
        $this->closer()->close($id);
        $this->assertSame([403, 'line_locked'], $this->code('POST', "/lines/$opening", 2, ['body' => 'The lamp came on.']));
    }

    #[Test]
    public function a_winning_line_takes_a_typo_fix_from_its_writer_until_the_next_line_lands(): void
    {
        $id = $this->start();
        $this->offer($id, 2, 'A gull landed on teh rail and looked at the lamp for a long time.');
        $this->runOut($id);
        $this->closer()->close($id);
        $won = Line::query()->where('story_id', $id)->where('position', 2)->value('id');

        // A typo, yes; a different line, no: it won with its own words.
        $this->assertSame([422, 'edit_too_big'], $this->code('POST', "/lines/$won", 2, ['body' => 'Then the whole town went to sleep, every last one of them.']));
        $this->assertSame(204, $this->api('POST', "/lines/$won", 2, ['body' => 'A gull landed on the rail and looked at the lamp for a long time.'])[0]);
        $this->assertSame('A gull landed on the rail and looked at the lamp for a long time.', Line::query()->find($won)->body);

        // The next line lands: the window closes.
        $this->offer($id, 3, 'Then the fog came in.');
        $this->runOut($id);
        $this->closer()->close($id);
        $this->assertSame([403, 'line_locked'], $this->code('POST', "/lines/$won", 2, ['body' => 'A gull landed on the rail.']));
    }

    #[Test]
    public function moderators_fix_the_opening_line_in_edit_story_but_never_an_erased_one(): void
    {
        $id = $this->start();
        $this->offer($id, 2, 'A gull landed on the rail.');
        $this->runOut($id);
        $this->closer()->close($id);

        $this->assertSame(200, $this->api('PATCH', "/$id", 1, ['title' => 'The Lighthouse', 'opening' => 'The lamp came on by itself, twice.'])[0]);
        $lines = Line::query()->where('story_id', $id)->orderBy('position')->get();
        $this->assertSame(['The lamp came on by itself, twice.', 'A gull landed on the rail.'], $lines->pluck('body')->all());
        $this->assertSame(1, $lines[0]->user_id);

        // Unchanged text is no edit at all.
        $this->assertSame(200, $this->api('PATCH', "/$id", 1, ['opening' => 'The lamp came on by itself, twice.'])[0]);

        // Moderators may fix any line, even a whole rewrite of a won one.
        $this->assertSame(204, $this->api('POST', '/lines/'.$lines[1]->id, 1, ['body' => 'A heron, not a gull, landed on the rail.'])[0]);

        Line::query()->where('id', $lines[0]->id)->update(['body' => '', 'hidden_at' => Carbon::now()]);
        $this->assertSame([409, 'line_erased'], $this->code('PATCH', "/$id", 1, ['opening' => 'Back again.']));
        $this->assertSame([409, 'line_erased'], $this->code('POST', '/lines/'.$lines[0]->id, 1, ['body' => 'Back again.']));
    }

    /** @return array{int, string|null} */
    private function code(string $method, string $path, int $as, array $body): array
    {
        [$status, $json] = $this->api($method, $path, $as, $body);

        return [$status, $json['errors'][0]['code'] ?? null];
    }
}
