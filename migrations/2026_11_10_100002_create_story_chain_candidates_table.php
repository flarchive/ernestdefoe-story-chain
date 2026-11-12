<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Lines offered for a round: one per member per round (the unique key is the
 * rule, not just the form). A moderator's removal hides the line and keeps
 * the row, so its writer can't simply post it again.
 */
return Migration::createTable('story_chain_candidates', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('story_id');
    $table->unsignedInteger('round');
    $table->unsignedInteger('user_id');
    $table->string('body', 500);
    $table->dateTime('created_at');
    $table->dateTime('hidden_at')->nullable();
    $table->unsignedInteger('hidden_by')->nullable();

    $table->unique(['story_id', 'round', 'user_id']);
    $table->index('user_id');
    $table->foreign('story_id')->references('id')->on('story_chain_stories')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
