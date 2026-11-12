<?php

namespace ErnestDefoe\StoryChain\Notification;

use ErnestDefoe\StoryChain\Story;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * To everyone taking part in a story when a round closes: "Mara's line was
 * added to The Lighthouse Keeper. Round 13 is open." or, at the end, "The
 * Lighthouse Keeper is finished" (a moderator may finish it with no line).
 * The subject is the recipient (a User: core hides notifications whose
 * subject is missing, and a subject without an API resource 500s every
 * list); the winner, when there is one, is the sender. The round in the data
 * makes it one alert per person per round, so a retried job sends nothing twice.
 */
class RoundBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Story $story, public User $recipient, public ?User $winner, public int $round, public bool $finished)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->winner;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->recipient;
    }

    public function getData(): mixed
    {
        return ['story' => $this->story->id, 'slug' => $this->story->slug, 'title' => $this->story->title, 'round' => $this->round, 'finished' => $this->finished];
    }

    public static function getType(): string
    {
        return 'storyChainRound';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
