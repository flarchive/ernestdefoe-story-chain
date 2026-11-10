import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import { span, storyUrl, t, type CardData } from '../api';

const HIDDEN = 'storyChainCardHidden';

function hiddenFor(): string | null {
  try {
    return localStorage.getItem(HIDDEN);
  } catch {
    return null;
  }
}

/**
 * Above the discussion list: the newest line of the story being written and
 * how long until the round closes. Hidden for the round on request; it
 * comes back when the next round opens.
 */
export default class IndexCard extends Component<{ card: CardData }> {
  private timer: number | undefined;

  oncreate(vnode: Mithril.VnodeDOM<{ card: CardData }>) {
    super.oncreate(vnode);
    this.timer = window.setInterval(() => !document.hidden && m.redraw(), 30000);
  }

  onremove(vnode: Mithril.VnodeDOM<{ card: CardData }>) {
    super.onremove(vnode);
    clearInterval(this.timer);
  }

  view() {
    const card = this.attrs.card;
    const key = `${card.id}:${card.round}`;
    if (hiddenFor() === key) return null;

    const left = Date.parse(card.endsAt) - Date.now();
    const href = storyUrl(card);

    return (
      <div className={`StoryChainCard StoryChain-cover--${card.cover}`}>
        <div className="StoryChainCard-spine" aria-hidden="true">
          <Icon name="fas fa-feather-pointed" />
        </div>
        <div className="StoryChainCard-body">
          <div className="StoryChainCard-meta">
            <span>{t('card.label')}</span>
            <span aria-hidden="true">·</span>
            <span>{t('story.round', { round: card.round })}</span>
            <span aria-hidden="true">·</span>
            <span className="StoryChainCard-clock">{left > 0 ? t('story.closes_in', { time: span(left) }) : t('story.closing')}</span>
          </div>
          <Link className="StoryChainCard-title" href={href}>
            {card.title}
          </Link>
          {card.last && (
            <p className="StoryChainCard-line">
              <span className="StoryChainCard-quote">…{card.last}</span>
              {card.by && <span className="StoryChainCard-by"> {card.by}</span>}
            </p>
          )}
          <div className="StoryChainCard-foot">
            <span>{t('card.offered', { count: card.offered })}</span>
          </div>
        </div>
        <div className="StoryChainCard-actions">
          <Link className="Button Button--primary StoryChainCard-cta" href={href}>
            {t('card.cta')}
          </Link>
          <button
            type="button"
            className="Button Button--icon Button--link StoryChainCard-hide"
            aria-label={app.translator.trans('ernestdefoe-story-chain.forum.card.hide', {}, true)}
            title={app.translator.trans('ernestdefoe-story-chain.forum.card.hide', {}, true)}
            onclick={() => {
              try {
                localStorage.setItem(HIDDEN, key);
              } catch {}
            }}
          >
            <Icon name="fas fa-xmark" />
          </button>
        </div>
      </div>
    );
  }
}
