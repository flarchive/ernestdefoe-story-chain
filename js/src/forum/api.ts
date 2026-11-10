import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export interface UserRow {
  id: number;
  username: string;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
}

export interface Story {
  id: number;
  slug: string;
  title: string;
  blurb: string | null;
  cover: string;
  status: 'active' | 'finished';
  round: number;
  endsAt: string;
  roundHours: number;
  maxLines: number | null;
  maxLength: number;
  finalRound: boolean;
  /** The open round is the last: the admin said so, or it brings the story to its length. */
  lastRound: boolean;
  lines: number;
  contributors: number;
  candidates: number;
  votes: number;
  creator: number | null;
  createdAt: string;
  lastLineAt: string | null;
  finishedAt: string | null;
  canModerate?: boolean;
  /** On the list: the newest line and how many lines are in the running. */
  last?: { body: string; user: number | null } | null;
  offered?: number;
}

export interface Line {
  id: number;
  position: number;
  round: number;
  user: number | null;
  body: string;
  votes: number;
  rivals: number;
  at: string;
  hidden: boolean;
}

export interface Candidate {
  id: number;
  body: string;
  mine: boolean;
  /** Only when the forum shows writers during a round, to moderators, or your own. */
  user?: number;
  /** Only when votes are counted live. */
  votes?: number;
  hidden?: boolean;
}

export interface Round {
  number: number;
  open: boolean;
  live: boolean;
  authors: boolean;
  offered: number;
  voters: number;
  candidates: Candidate[];
  myVote: number | null;
  /** null = may offer a line now; otherwise why not. */
  write: null | 'guest' | 'permission' | 'closed' | 'offered' | 'repeat';
  canVote: boolean;
}

export interface Detail {
  story: Story;
  lines: Line[];
  round: Round | null;
  credits: [userId: number, lines: number][];
  users: UserRow[];
  now: string;
}

export interface Listing {
  active?: Story[];
  canStart?: boolean;
  library: Story[];
  more: boolean;
  users: UserRow[];
  now: string;
}

export interface CardData {
  id: number;
  slug: string;
  title: string;
  cover: string;
  round: number;
  endsAt: string;
  lines: number;
  maxLines: number | null;
  last: string | null;
  by: string | null;
  offered: number;
}

export const COVERS = ['oxblood', 'navy', 'forest', 'plum', 'teal', 'amber', 'slate', 'rose'];
export const HOURS = [1, 6, 12, 24, 48, 72, 168];

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-story-chain.forum.${key}`, params);

export function storyUrl(story: { id: number; slug: string }): string {
  return app.route('story-chain.story', { id: `${story.id}-${story.slug}` });
}

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-story-chain.forum.errors.${code}`;
  if (code && app.translator.trans(key) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  if (error?.status === 404) return extractText(t('errors.story_not_found'));
  return extractText(app.translator.trans('core.lib.error.generic_message'));
}

export function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, data?: Record<string, any>, quiet = false): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + '/stories' + path;
  return app.request<T>({
    method,
    url: method === 'GET' && data ? url + '?' + new URLSearchParams(data).toString() : url,
    body: method === 'GET' ? undefined : data,
    background: method === 'GET',
    errorHandler: (error: any) => {
      if (!quiet) app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

/** Server time minus ours, so countdowns agree with the server's clock. */
export let skew = 0;
export function setSkew(serverNow: string): void {
  const value = Date.parse(serverNow) - Date.now();
  if (Number.isFinite(value)) skew = value;
}
export function now(): number {
  return Date.now() + skew;
}

/** "2d 4h", "5h 07m", "12:09": how long until a moment. */
export function span(ms: number): string {
  const s = Math.max(0, Math.floor(ms / 1000));
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const mm = Math.floor((s % 3600) / 60);
  if (d) return `${d}d ${h}h`;
  if (h) return `${h}h ${String(mm).padStart(2, '0')}m`;
  return `${mm}:${String(s % 60).padStart(2, '0')}`;
}
