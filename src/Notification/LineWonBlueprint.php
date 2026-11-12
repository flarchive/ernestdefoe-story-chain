<?php

namespace ErnestDefoe\StoryChain\Notification;

use ErnestDefoe\StoryChain\Story;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "Your line won round 12 of The Lighthouse Keeper": to its writer. The
 * subject is the writer (a User has an API resource; a subject without one
 * 500s every notification list); the story rides in the data, which also
 * makes the blueprint one per round, so a retried job sends nothing twice.
 */
class LineWonBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Story $story, public User $writer, public int $round, public string $line, public bool $finished)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->writer;
    }

    public function getData(): mixed
    {
        return ['story' => $this->story->id, 'slug' => $this->story->slug, 'title' => $this->story->title, 'round' => $this->round, 'finished' => $this->finished];
    }

    public static function getType(): string
    {
        return 'storyChainWon';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-story-chain::emails.plain.won',
            'html' => 'ernestdefoe-story-chain::emails.html.won',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-story-chain.email.won.subject', ['{title}' => $this->story->title]);
    }
}
