<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The story itself. Round 0 is the opening line; every other line won its
 * round, and (story_id, round) is unique, so a round can never add two lines
 * however many closers race. A moderator hides a line (hidden_at) rather
 * than deleting it: positions never shift and it can be put back.
 */
return Migration::createTable('story_chain_lines', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('story_id');
    $table->unsignedInteger('position');
    $table->unsignedInteger('round');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('body', 500);
    $table->unsignedInteger('votes')->default(0);
    $table->unsignedInteger('rivals')->default(0);
    $table->dateTime('created_at');
    $table->dateTime('hidden_at')->nullable();
    $table->unsignedInteger('hidden_by')->nullable();

    $table->unique(['story_id', 'round']);
    $table->index(['story_id', 'position']);
    $table->index('user_id');
    $table->foreign('story_id')->references('id')->on('story_chain_stories')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
