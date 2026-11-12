import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, now, setSkew, span, t, type Candidate, type Detail, type Line, type Round } from '../api';
import { Book, Person, date, pushUsers, useClock, user } from './parts';
import { IndexSidebar, PageStructure, openLineForm, openStoryForm } from '../pages';

/** A steady hue per writer, for "Color by writer". */
const hue = (id: number | null) => (id ? (id * 137) % 360 : 0);

/**
 * /stories/:id: the story as a book page (hover, focus or tap a line for its
 * writer), then the open round: the countdown, the box for your line and the
 * lines in the running with their votes; or, for a finished story, the end,
 * its numbers and its writers.
 */
export default class StoryPage extends Page {
  data: Detail | null = null;
  error = false;
  draft = '';
  sending = false;
  voting: number | null = null;
  byWriter = false;
  /** The line whose card is showing, and whether a click pinned it. */
  focus: { line: Line; top: number; left: number } | null = null;
  pinned = false;
  private stopClock!: () => void;
  private refresh: number | undefined;
  private reloadAt = 0;
  private outside = (e: Event) => {
    const target = e.target as HTMLElement;
    if (this.pinned && !target.closest('.StoryChainText-line, .StoryChainLineCard')) {
      this.pinned = false;
      this.focus = null;
      m.redraw();
    }
  };

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.stopClock = useClock();
    this.load();
    this.refresh = window.setInterval(() => !document.hidden && this.load(true), 60000);
    document.addEventListener('click', this.outside);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.stopClock();
    clearInterval(this.refresh);
    document.removeEventListener('click', this.outside);
  }

  id(): string {
    return String(m.route.param('id') || '').split('-')[0];
  }

  load(quiet = false) {
    call<Detail>('GET', `/${this.id()}`, undefined, true)
      .then((data) => {
        pushUsers(data.users);
        setSkew(data.now);
        if (!this.data) {
          app.history.push('story-chain.story', data.story.title);
        }
        app.setTitle(data.story.title);
        app.setTitleCount(0);
        this.data = data;
        this.reloadAt = 0;
      })
      .catch(() => (this.error = !quiet && !this.data))
      .finally(() => m.redraw());
  }

  view() {
    return (
      <PageStructure className="StoryChainPage StoryChainStoryPage" sidebar={() => <IndexSidebar />}>
        {this.body()}
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    const data = this.data;
    const back = (
      <Link className="StoryChainBack" href={app.route('story-chain')}>
        <Icon name="fas fa-arrow-left" /> {t('page.back')}
      </Link>
    );
    if (!data) return [back, this.error ? <p className="StoryChainEmpty">{t('page.not_found')}</p> : <LoadingIndicator />];

    const story = data.story;
    const round = data.round;

    // The round ran out: fetch the result after a short random wait, so open pages don't all ask at once.
    if (round?.open && Date.parse(story.endsAt) <= now() && !this.reloadAt) {
      this.reloadAt = Date.now();
      window.setTimeout(() => this.load(true), 1500 + Math.random() * 3500);
    }

    return (
      <div className={classList('StoryChainStory', `StoryChain-cover--${story.cover}`)}>
        {back}
        {this.header()}
        {this.text()}
        {story.status === 'finished' ? this.ending() : round && this.round(round)}
        {this.credits()}
      </div>
    );
  }

  header(): Mithril.Children {
    const story = this.data!.story;
    const finished = story.status === 'finished';

    return (
      <header className="StoryChainHeader">
        {Book({ story, size: 'small' })}
        <div className="StoryChainHeader-text">
          <div className="StoryChainHeader-kicker">
            {finished ? (
              <span className="StoryChainBadge StoryChainBadge--done">
                <Icon name="fas fa-book" /> {t('story.finished')}
              </span>
            ) : (
              <span>{t('story.round', { round: story.round })}</span>
            )}
            {!finished && story.lastRound && <span className="StoryChainBadge">{t('story.final')}</span>}
          </div>
          <h1 className="StoryChainHeader-title">{story.title}</h1>
          {story.blurb && <p className="StoryChainHeader-blurb">{story.blurb}</p>}
          <p className="StoryChainHeader-meta">
            {finished && story.finishedAt
              ? t('story.finished_on', { date: date(story.finishedAt) })
              : t('story.started', { date: date(story.createdAt) })}
            {' · '}
            {story.maxLines && !finished ? t('story.line_of', { n: story.lines, max: story.maxLines }) : t('story.lines', { count: story.lines })}
            {' · '}
            {t('story.writers', { count: story.contributors })}
          </p>
        </div>
        {story.canModerate && this.manage()}
      </header>
    );
  }

  manage(): Mithril.Children {
    const story = this.data!.story;
    const active = story.status === 'active';
    const patch = (body: Record<string, any>) =>
      call('PATCH', `/${story.id}`, body)
        .then(() => this.load())
        .catch(() => {});

    return (
      <Dropdown
        className="StoryChainManage"
        buttonClassName="Button"
        menuClassName="Dropdown-menu--right"
        label={t('manage.button')}
        icon="fas fa-sliders"
        accessibleToggleLabel={extractText(t('manage.button'))}
      >
        <Button
          icon="fas fa-pen"
          onclick={() => openStoryForm({ story, opening: this.data!.lines.find((line) => line.position === 1), onsave: () => this.load() })}
        >
          {t('manage.edit')}
        </Button>
        {active && (
          <Button icon="fas fa-forward-step" onclick={() => confirm(extractText(t('manage.confirm_close'))) && patch({ action: 'close' })}>
            {t('manage.close')}
          </Button>
        )}
        {active && (
          <Button icon="fas fa-flag-checkered" onclick={() => patch({ finalRound: !story.finalRound })}>
            {t(story.finalRound ? 'manage.not_final' : 'manage.final')}
          </Button>
        )}
        {active && (
          <Button
            icon="fas fa-book"
            onclick={() => confirm(extractText(t('manage.confirm_finish', { title: story.title }))) && patch({ action: 'finish' })}
          >
            {t('manage.finish')}
          </Button>
        )}
        <Button
          icon="fas fa-trash"
          className="StoryChainManage-danger"
          onclick={() =>
            confirm(extractText(t('manage.confirm_delete', { title: story.title }))) &&
            call('DELETE', `/${story.id}`)
              .then(() => m.route.set(app.route('story-chain')))
              .catch(() => {})
          }
        >
          {t('manage.delete')}
        </Button>
      </Dropdown>
    );
  }

  text(): Mithril.Children {
    const data = this.data!;
    const show = (line: Line, target: HTMLElement) => {
      const box = target.closest('.StoryChainText')!.getBoundingClientRect();
      const rect = target.getClientRects()[0] || target.getBoundingClientRect();
      this.focus = { line, top: rect.bottom - box.top + 8, left: Math.max(0, Math.min(rect.left - box.left, box.width - 300)) };
    };
    const hide = (line: Line) => {
      if (!this.pinned && this.focus?.line.id === line.id) this.focus = null;
    };

    return (
      <section className="StoryChainReader">
        <div className="StoryChainReader-tools">
          <label className="StoryChainToggle">
            <input type="checkbox" checked={this.byWriter} onchange={(e: Event) => (this.byWriter = (e.target as HTMLInputElement).checked)} />{' '}
            {t('story.color_by_writer')}
          </label>
        </div>
        <article className={classList('StoryChainText', { 'StoryChainText--writers': this.byWriter })}>
          <p>
            {data.lines.map((line, i) => [
              <span
                className={classList('StoryChainText-line', {
                  'StoryChainText-line--first': i === 0,
                  'StoryChainText-line--hidden': line.hidden,
                  'StoryChainText-line--active': this.focus?.line.id === line.id,
                })}
                style={{ '--sc-hue': String(hue(line.user)) }}
                tabindex="0"
                role="button"
                aria-label={extractText(this.lineLabel(line))}
                onpointerenter={(e: PointerEvent) => e.pointerType === 'mouse' && !this.pinned && show(line, e.currentTarget as HTMLElement)}
                onpointerleave={(e: PointerEvent) => e.pointerType === 'mouse' && hide(line)}
                onfocus={(e: FocusEvent) => !this.pinned && show(line, e.currentTarget as HTMLElement)}
                onblur={() => hide(line)}
                onclick={(e: MouseEvent) => {
                  const same = this.pinned && this.focus?.line.id === line.id;
                  this.pinned = !same;
                  if (same) this.focus = null;
                  else show(line, e.currentTarget as HTMLElement);
                }}
              >
                {line.body}
              </span>,
              ' ',
            ])}
          </p>
          {this.focus && this.lineCard(this.focus.line)}
        </article>
      </section>
    );
  }

  lineLabel(line: Line): Mithril.Children {
    const person = user(line.user);
    const name = person ? person.displayName() : extractText(t('story.deleted_user'));
    return `${name}: ${line.round ? extractText(t('story.line_votes', { round: line.round, votes: line.votes })) : extractText(t('story.opening'))}`;
  }

  /** Mirrors Line::rewrite: moderators any line; its writer while it's the newest line of a story still being written. */
  canFix(line: Line): boolean {
    const story = this.data!.story;
    if (line.body === '') return false;
    if (story.canModerate) return true;
    const me = app.session.user;
    return !!me && line.user === Number(me.id()) && story.status === 'active' && !line.hidden && line.round === story.round - 1;
  }

  lineCard(line: Line): Mithril.Children {
    const story = this.data!.story;
    const focus = this.focus!;

    return (
      <div className="StoryChainLineCard" style={{ top: `${focus.top}px`, left: `${focus.left}px` }} role="status">
        <div className="StoryChainLineCard-who">{Person({ id: line.user })}</div>
        <div className="StoryChainLineCard-what">
          {line.round ? t('story.line_votes', { round: line.round, votes: line.votes }) : t('story.opening')}
          {line.round > 0 && line.rivals > 0 && <span> · {t('story.line_rivals', { count: line.rivals })}</span>}
        </div>
        <div className="StoryChainLineCard-when">{date(line.at)}</div>
        {line.hidden && <div className="StoryChainLineCard-hidden">{t('story.removed_line')}</div>}
        {this.canFix(line) && (
          <Button
            className="Button Button--link StoryChainLineCard-mod"
            icon="fas fa-pen"
            onclick={() => {
              this.focus = null;
              this.pinned = false;
              openLineForm({ line, story, onsave: () => this.load() });
            }}
          >
            {t('manage.fix_line')}
          </Button>
        )}
        {story.canModerate && (
          <Button
            className="Button Button--link StoryChainLineCard-mod"
            icon={line.hidden ? 'fas fa-rotate-left' : 'fas fa-eye-slash'}
            onclick={() =>
              call('POST', `/lines/${line.id}`, { hidden: !line.hidden })
                .then(() => {
                  this.focus = null;
                  this.pinned = false;
                  this.load();
                })
                .catch(() => {})
            }
          >
            {t(line.hidden ? 'manage.restore_line' : 'manage.remove_line')}
          </Button>
        )}
      </div>
    );
  }

  round(round: Round): Mithril.Children {
    const data = this.data!;
    const story = data.story;
    const left = Date.parse(story.endsAt) - now();
    const total = story.roundHours * 3600000;
    const progress = Math.min(100, Math.max(0, 100 - (left / total) * 100));
    const last = [...data.lines].reverse().find((line) => !line.hidden && line.round > 0 && line.round === round.number - 1);

    return (
      <section className="StoryChainRound" aria-labelledby="sc-round">
        <div className="StoryChainRound-head">
          <div>
            <h2 id="sc-round" className="StoryChainRound-title">
              {t('story.round', { round: round.number })}
              {story.lastRound && <span className="StoryChainBadge">{t('story.final')}</span>}
            </h2>
            {last && (
              <p className="StoryChainRound-last">
                {t('last.text', {
                  name: user(last.user)?.displayName() || extractText(t('story.deleted_user')),
                  votes: last.votes,
                  rivals: last.rivals,
                })}
              </p>
            )}
          </div>
          <div className="StoryChainClock" role="timer">
            <span className="StoryChainClock-label">{left > 0 ? t('story.closes_in', { time: '' }) : t('story.closing')}</span>
            {left > 0 && <span className="StoryChainClock-time">{span(left)}</span>}
            <span className="StoryChainClock-bar">
              <span style={{ width: `${progress}%` }} />
            </span>
          </div>
        </div>

        {this.write(round)}
        {this.candidates(round)}
      </section>
    );
  }

  write(round: Round): Mithril.Children {
    const data = this.data!;
    const story = data.story;
    const mine = round.candidates.find((c) => c.mine);

    if (mine) {
      return (
        <div className={classList('StoryChainMine', { 'StoryChainMine--removed': mine.hidden })}>
          <div className="StoryChainMine-label">{t('write.yours')}</div>
          <p className="StoryChainMine-body">{mine.body}</p>
          {mine.hidden ? (
            <p className="StoryChainNote">{t('write.removed')}</p>
          ) : (
            round.open && (
              <Button className="Button Button--link" icon="fas fa-rotate-left" onclick={() => this.withdraw(mine)}>
                {t('write.withdraw')}
              </Button>
            )
          )}
        </div>
      );
    }

    if (round.write === 'guest') {
      return (
        <div className="StoryChainNote StoryChainNote--box">
          <Button className="Button Button--primary" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
            {app.translator.trans('core.forum.header.log_in_link')}
          </Button>
          <span>{t('write.guest')}</span>
        </div>
      );
    }
    if (round.write === 'repeat' || round.write === 'permission') {
      return <p className="StoryChainNote StoryChainNote--box">{t(`write.${round.write}`)}</p>;
    }
    if (round.write !== null) return null;

    const last = [...data.lines].reverse().find((line) => !line.hidden);
    const length = this.draft.trim().length;

    return (
      <form className="StoryChainWrite" onsubmit={(e: SubmitEvent) => this.offer(e)}>
        <label className="StoryChainWrite-label" for="sc-line">
          {t('write.heading')}
        </label>
        {last && (
          <p className="StoryChainWrite-context">
            <span>{t('story.so_far')}</span> <q>{last.body}</q>
          </p>
        )}
        <textarea
          id="sc-line"
          className="FormControl StoryChain-serif"
          rows={3}
          maxlength={story.maxLength}
          placeholder={extractText(t('write.placeholder'))}
          value={this.draft}
          oninput={(e: InputEvent) => (this.draft = (e.target as HTMLTextAreaElement).value.replace(/\s*\n\s*/g, ' '))}
        />
        <div className="StoryChainWrite-foot">
          <span className={classList('StoryChainCounter', { 'StoryChainCounter--near': length > story.maxLength * 0.9 })}>
            {length} / {story.maxLength}
          </span>
          <Button type="submit" className="Button Button--primary" icon="fas fa-feather-pointed" loading={this.sending} disabled={length < 2}>
            {t('write.submit')}
          </Button>
        </div>
      </form>
    );
  }

  candidates(round: Round): Mithril.Children {
    const story = this.data!.story;
    const shown = round.candidates.filter((c) => !c.mine);
    const most = Math.max(1, ...round.candidates.map((c) => c.votes || 0));

    return (
      <div className="StoryChainRunning">
        <div className="StoryChainRunning-head">
          <h3>{t('vote.heading', { count: round.offered })}</h3>
          <span className="StoryChainNote">
            {round.live ? t('vote.live_note') : t('vote.hidden_note')}
            {round.voters > 0 && !round.live && <> · {t('vote.voters', { count: round.voters })}</>}
          </span>
        </div>
        {!round.candidates.length && <p className="StoryChainEmpty">{t('vote.empty')}</p>}
        <ol className="StoryChainRunning-list">
          {round.candidates.map((c) => {
            const voted = round.myVote === c.id;
            return (
              <li
                className={classList('StoryChainCandidate', {
                  'StoryChainCandidate--voted': voted,
                  'StoryChainCandidate--mine': c.mine,
                  'StoryChainCandidate--removed': c.hidden,
                })}
              >
                <p className="StoryChainCandidate-body">{c.body}</p>
                {round.live && c.votes !== undefined && (
                  <div className="StoryChainCandidate-bar" aria-hidden="true">
                    <span style={{ width: `${(100 * (c.votes || 0)) / most}%` }} />
                  </div>
                )}
                {(c.user || round.live || c.hidden || c.mine || story.canModerate || (round.canVote && round.open)) && (
                  <div className="StoryChainCandidate-foot">
                    {c.user ? Person({ id: c.user }) : <span />}
                    <span className="StoryChainCandidate-actions">
                      {round.live && c.votes !== undefined && (
                        <span className="StoryChainCandidate-votes">{t('vote.count', { count: c.votes })}</span>
                      )}
                      {c.hidden && <span className="StoryChainBadge StoryChainBadge--muted">{t('vote.removed')}</span>}
                      {c.mine && !c.hidden && <span className="StoryChainBadge StoryChainBadge--muted">{t('vote.own')}</span>}
                      {story.canModerate && round.open && (
                        <Button
                          className="Button Button--link"
                          icon={c.hidden ? 'fas fa-rotate-left' : 'fas fa-eye-slash'}
                          onclick={() => this.moderate(c)}
                        >
                          {c.hidden ? t('manage.restore_line') : t('manage.remove_line')}
                        </Button>
                      )}
                      {round.canVote && round.open && !c.mine && !c.hidden && (
                        <Button
                          className={classList('Button', 'StoryChainVote', voted && 'Button--primary')}
                          icon={voted ? 'fas fa-check' : 'far fa-heart'}
                          loading={this.voting === c.id}
                          aria-pressed={voted ? 'true' : 'false'}
                          onclick={() => this.vote(voted ? null : c.id, c.id)}
                        >
                          {voted ? t('vote.voted') : t('vote.vote')}
                        </Button>
                      )}
                    </span>
                  </div>
                )}
              </li>
            );
          })}
        </ol>
        {shown.length > 1 && <p className="StoryChainNote StoryChainRunning-ties">{t('vote.ties')}</p>}
      </div>
    );
  }

  ending(): Mithril.Children {
    const story = this.data!.story;
    const days = Math.max(1, Math.round((Date.parse(story.finishedAt || story.createdAt) - Date.parse(story.createdAt)) / 86400000));
    const stats: [number, string][] = [
      [story.lines, 'stats.lines'],
      [story.contributors, 'stats.writers'],
      [story.candidates, 'stats.offered'],
      [story.votes, 'stats.votes'],
      [days, 'stats.days'],
    ];

    return (
      <section className="StoryChainEnd">
        <p className="StoryChainEnd-mark">{t('story.the_end')}</p>
        <h2 className="StoryChainSection-title">{t('stats.heading')}</h2>
        <dl className="StoryChainStats">
          {stats.map(([n, key]) => (
            <div className="StoryChainStats-item">
              <dt>{t(key, { count: n })}</dt>
              <dd>{n.toLocaleString()}</dd>
            </div>
          ))}
        </dl>
      </section>
    );
  }

  credits(): Mithril.Children {
    const credits = this.data!.credits;
    if (!credits.length) return null;

    return (
      <section className="StoryChainCredits">
        <h2 className="StoryChainSection-title">{t('credits.heading')}</h2>
        <ul className="StoryChainCredits-list">
          {credits.map(([id, lines]) => {
            const person = user(id);
            return (
              <li className="StoryChainCredits-item" style={{ '--sc-hue': String(hue(id)) }}>
                {person ? (
                  <Link href={app.route.user(person)} className="StoryChainCredits-person">
                    <Avatar user={person} />
                    <span className="StoryChainCredits-name">{person.displayName()}</span>
                  </Link>
                ) : (
                  <span className="StoryChainCredits-person">{t('story.deleted_user')}</span>
                )}
                <span className="StoryChainCredits-lines">{t('credits.lines', { count: lines })}</span>
              </li>
            );
          })}
        </ul>
      </section>
    );
  }

  offer(e: SubmitEvent) {
    e.preventDefault();
    const story = this.data!.story;
    this.sending = true;
    call('POST', `/${story.id}/lines`, { body: this.draft })
      .then(() => {
        this.draft = '';
        app.alerts.show({ type: 'success' }, t('write.thanks'));
        this.load();
      })
      .catch(() => this.load(true))
      .finally(() => {
        this.sending = false;
        m.redraw();
      });
  }

  withdraw(candidate: Candidate) {
    call('DELETE', `/candidates/${candidate.id}`)
      .then(() => {
        this.draft = candidate.body;
        this.load();
      })
      .catch(() => this.load(true));
  }

  vote(candidate: number | null, pressed: number) {
    const story = this.data!.story;
    const round = this.data!.round!;
    const before = round.myVote;
    round.myVote = candidate;
    this.voting = pressed;
    call('POST', `/${story.id}/vote`, { candidate })
      .then(() => round.live && this.load(true))
      .catch(() => {
        round.myVote = before;
        this.load(true);
      })
      .finally(() => {
        this.voting = null;
        m.redraw();
      });
  }

  moderate(candidate: Candidate) {
    call('POST', `/candidates/${candidate.id}`, { hidden: !candidate.hidden })
      .then(() => this.load())
      .catch(() => {});
  }
}
