import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
import { t } from './api';

const href = (notification: any) => {
  const data = notification.content() || {};
  return app.route('story-chain.story', { id: `${data.story ?? ''}-${data.slug ?? ''}` });
};

/** To the writer: their line won a round. */
export class WonNotification extends Notification {
  icon() {
    return 'fas fa-feather-pointed';
  }

  href() {
    return href(this.attrs.notification);
  }

  content(): Mithril.Children {
    const data: any = (this.attrs.notification as any).content() || {};
    return t(data.finished ? 'notification.won_final' : 'notification.won', { title: data.title, round: data.round });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}

/** To everyone taking part: a line was added and the next round is open, or the story is finished. */
export class RoundNotification extends Notification {
  icon() {
    return 'fas fa-book-open';
  }

  href() {
    return href(this.attrs.notification);
  }

  content(): Mithril.Children {
    const notification: any = this.attrs.notification;
    const data = notification.content() || {};
    if (data.finished) return t('notification.finished', { title: data.title });
    return t('notification.round', { username: notification.fromUser()?.displayName(), title: data.title, round: data.round });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}
