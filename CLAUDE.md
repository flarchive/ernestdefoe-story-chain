# Story Chain: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **The Closer is the only thing that adds lines or moves rounds** (`Closer::close`). It locks the story row, re-checks the round has run out (or a moderator's `close`), and moves the story with a conditional UPDATE on `round` + `status` (`RaceLost` rolls back otherwise). Lines are unique per `(story_id, round)`. Never add another path that inserts a line or bumps `round`.
- **Winner = most votes, then lowest candidate id** (`Candidate::scopeRanked`). Ids rise with every submission, so a withdrawn and re-offered line counts from its new submission.
- **Every write that touches a round** (offer, withdraw, vote, moderate a candidate or line) runs inside a transaction with `Story::locked()` and re-checks `isOpen()` and the round inside the lock.
- **Hidden means not in the payload.** With live votes off, candidates carry no `votes`; with writers hidden, no `user` (moderators and the writer of their own line excepted). Their order is a per-viewer shuffle (crc32 of id, viewer, round) so the list order can't leak the count. Don't add timestamps or anything else that would let a viewer infer either.
- A moderator's removal hides (`hidden_at`), never deletes: a removed candidate keeps its writer's slot for the round (they can't offer it again or withdraw it), and its votes are deleted so voters can vote again. A removed story line keeps its position; `line_count` and `contributor_count` are recounted.
- **Alerts go on the queue after the commit** (`Job\SendRoundNews`): a closed round, and a moderator's Finish. "Your line won" has the writer as subject; "round open / finished" has the RECIPIENT as subject (one blueprint per person per round, so syncing one never deletes another's, and a retry sends nothing twice) and the winner as sender, or no sender when a moderator finished it. Never a null subject (core hides notifications whose subject is missing) and never a subject without an API resource (500s every list). Recipients are filtered by the groups holding `storyChain.view`, never a permission query per person.
- **Leaving** (`Forget::user`): GDPR anonymize and account deletion (`Deleting` listener) take the name off a member's lines; GDPR erase also blanks the text (`body = ''`) and hides it, and `LineController` refuses to restore a blank line. Every story they wrote in is recounted, finished ones too.
- **Index and foreign key names are Laravel's own** (prefixed with the table prefix). Never hand-name one: an unprefixed FK name collides between two forums in one MySQL database. The test checks every name fits 64 characters with a 9-character prefix.
- **The index card is cached** (`CardCache`, 10 minutes) so the forum payload costs no query; anything that changes what it shows calls `forget()`.
- Member text is one clean line (`Text::clean`) and rendered as Mithril text nodes. In the HTML email it is swapped in escaped AFTER the formatter runs (placeholders), so Markdown in a title or line can never become a link.
- LESS evaluates `hsl()`, `min()`, `max()`: escape them (`~"hsl(...)"`). Link rules carry `html:not(#x-hi)` to beat Bespoke's link colour. `Book()`/`Person()` in `parts.tsx` are plain function calls, not JSX components (Mithril treats a function as a closure component).
- Translation params never named `user` (Flarum's translator treats it as a User model).

## Testing

- `composer test:setup && composer test` (integration; prefixed SQLite and MySQL both pass), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- The showcase seed is `~/github/showcase/seeds/99-story-chain.php` (re-run nightly; dates are relative to now).
