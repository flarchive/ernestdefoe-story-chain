import app from 'flarum/forum/app';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { t, type Story, type UserRow } from '../api';

/** People arrive as one compact list; the forum's store lets Avatar and names draw them the usual way. */
export function pushUsers(users: UserRow[]): void {
  for (const user of users) {
    app.store.pushObject({
      type: 'users',
      id: String(user.id),
      attributes: { username: user.username, displayName: user.displayName, slug: user.slug, avatarUrl: user.avatarUrl },
    } as any);
  }
}

export function user(id: number | null | undefined): any {
  return id ? app.store.getById('users', String(id)) : null;
}

export function Person({ id, avatar = true }: { id: number | null | undefined; avatar?: boolean }): Mithril.Children {
  const person = user(id);
  if (!person) return <span className="StoryChainPerson StoryChainPerson--gone">{t('story.deleted_user')}</span>;
  return (
    <Link className="StoryChainPerson" href={app.route.user(person)}>
      {avatar && <Avatar user={person} />}
      <span className="StoryChainPerson-name">{person.displayName()}</span>
    </Link>
  );
}

/** A book from the library: cover colour, title, ornament and its size. */
export function Book({ story, size = 'normal' }: { story: Story; size?: 'normal' | 'small' }): Mithril.Children {
  return (
    <div className={classList('StoryChainBook', `StoryChain-cover--${story.cover}`, size === 'small' && 'StoryChainBook--small')} aria-hidden="true">
      <span className="StoryChainBook-spine" />
      <span className="StoryChainBook-face">
        <span className="StoryChainBook-kicker">{t('story.chapter')}</span>
        <span className="StoryChainBook-title">{story.title}</span>
        <span className="StoryChainBook-rule">❦</span>
        {size === 'normal' && (
          <span className="StoryChainBook-meta">
            {t('story.lines', { count: story.lines })} · {t('story.writers', { count: story.contributors })}
          </span>
        )}
      </span>
    </div>
  );
}

let ticking = 0;
let clock: number | undefined;

/** One shared one-second tick while any countdown is on screen (and the tab is seen). */
export function useClock(): () => void {
  if (!ticking++) clock = window.setInterval(() => !document.hidden && m.redraw(), 1000);
  return () => {
    if (!--ticking) clearInterval(clock);
  };
}

export function date(iso: string): string {
  try {
    return new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { month: 'long', day: 'numeric', year: 'numeric' }).format(new Date(iso));
  } catch {
    return new Date(iso).toLocaleDateString();
  }
}
