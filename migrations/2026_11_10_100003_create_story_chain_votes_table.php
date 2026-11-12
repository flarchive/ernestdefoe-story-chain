<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/* One vote per member per round, which they may move until the round closes. */
return Migration::createTable('story_chain_votes', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('story_id');
    $table->unsignedInteger('round');
    $table->unsignedInteger('candidate_id');
    $table->unsignedInteger('user_id');
    $table->dateTime('created_at');

    $table->unique(['story_id', 'round', 'user_id']);
    $table->index('candidate_id');
    $table->index('user_id');
    $table->foreign('story_id')->references('id')->on('story_chain_stories')->cascadeOnDelete();
    $table->foreign('candidate_id')->references('id')->on('story_chain_candidates')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
