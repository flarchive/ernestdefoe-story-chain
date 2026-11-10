<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Anyone may read, members write and vote, moderators start stories and moderate.
return Migration::addPermissions([
    'storyChain.view' => Group::GUEST_ID,
    'storyChain.write' => Group::MEMBER_ID,
    'storyChain.vote' => Group::MEMBER_ID,
    'storyChain.start' => Group::MODERATOR_ID,
    'storyChain.moderate' => Group::MODERATOR_ID,
]);
