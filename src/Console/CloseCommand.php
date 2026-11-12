<?php

namespace ErnestDefoe\StoryChain\Console;

use ErnestDefoe\StoryChain\Closer;
use Flarum\Console\AbstractCommand;

/** Scheduled every minute (extend.php). Safe to run by hand, or twice at once: see Closer. */
class CloseCommand extends AbstractCommand
{
    public function __construct(private readonly Closer $closer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('story-chain:close')->setDescription('Close the Story Chain rounds that have run out.');
    }

    protected function fire(): int
    {
        $closed = $this->closer->run();
        if ($closed) {
            $this->info("Closed {$closed}.");
        }

        return 0;
    }
}
