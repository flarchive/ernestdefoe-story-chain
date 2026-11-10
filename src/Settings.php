<?php

namespace ErnestDefoe\StoryChain;

final class Settings
{
    public const PREFIX = 'ernestdefoe-story-chain.';

    public const NAV = self::PREFIX.'nav';
    public const CARD = self::PREFIX.'index_card';
    /** Show vote counts while a round is open (otherwise they're revealed when it closes). */
    public const LIVE_VOTES = self::PREFIX.'live_votes';
    /** Show who wrote each candidate while the round is open. */
    public const SHOW_AUTHORS = self::PREFIX.'show_authors';
    /** The writer of the newest line sits the next round out. */
    public const NO_REPEAT = self::PREFIX.'no_repeat';

    public const VIEW = 'storyChain.view';
    public const WRITE = 'storyChain.write';
    public const VOTE = 'storyChain.vote';
    public const START = 'storyChain.start';
    public const MODERATE = 'storyChain.moderate';

    public const COVERS = ['oxblood', 'navy', 'forest', 'plum', 'teal', 'amber', 'slate', 'rose'];
    public const ROUND_HOURS = [1, 6, 12, 24, 48, 72, 168];
    public const MIN_LENGTH = 40;
    public const MAX_LENGTH = 500;
    public const MAX_LINES = 500;
}
