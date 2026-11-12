import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, now, setSkew, span, storyUrl, t, type Listing, type Story } from '../api';
import { Book, Person, pushUsers, useClock } from './parts';
import { IndexSidebar, PageStructure, openStoryForm } from '../pages';

/** /stories: the stories being written and the library of finished ones. */
export default class StoriesPage extends Page {
  data: Listing | null = null;
  error = false;
  loadingMore = false;
  private stopClock!: () => void;
  private refresh: number | undefined;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('story-chain', extractText(t('page.title')));
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);
    this.stopClock = useClock();
    this.load();
    this.refresh = window.setInterval(() => !document.hidden && this.load(), 60000);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.stopClock();
    clearInterval(this.refresh);
  }

  load() {
    call<Listing>('GET', '', undefined, !!this.data)
      .then((data) => {
        pushUsers(data.users);
        setSkew(data.now);
        // Keep library pages already loaded.
        if (this.data && this.data.library.length > data.library.length) {
          data.library = this.data.library;
          data.more = this.data.more;
        }
        this.data = data;
      })
      .catch(() => (this.error = !this.data))
      .finally(() => m.redraw());
  }

  more() {
    const data = this.data!;
    this.loadingMore = true;
    call<Listing>('GET', '', { library: String(data.library.length) })
      .then((page) => {
        data.library.push(...page.library);
        data.more = page.more;
      })
      .catch(() => {})
      .finally(() => {
        this.loadingMore = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="StoryChainPage" sidebar={() => <IndexSidebar />}>
        {this.body()}
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    const data = this.data;
    const header = (
      <header className="StoryChainHero">
        <div className="StoryChainHero-text">
          <h1 className="StoryChainHero-title">
            <Icon name="fas fa-feather-pointed" /> {t('page.title')}
          </h1>
          <p className="StoryChainHero-lead">{t('page.lead')}</p>
        </div>
        {data?.canStart && (
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => openStoryForm({})}>
            {t('page.start')}
          </Button>
        )}
      </header>
    );

    if (!data) return [header, this.error ? <p className="StoryChainEmpty">{t('page.load_error')}</p> : <LoadingIndicator />];

    const active = data.active || [];

    return (
      <div className="StoryChainIndex">
        {header}
        {!active.length && !data.library.length ? (
          <div className="StoryChainEmpty StoryChainEmpty--big">
            <Icon name="fas fa-book-open" />
            <p>{t('page.empty')}</p>
            {data.canStart && <p>{t('page.empty_start')}</p>}
          </div>
        ) : (
          [
            active.length > 0 && (
              <section className="StoryChainSection">
                <h2 className="StoryChainSection-title">{t('page.writing')}</h2>
                <div className="StoryChainActive-list">{active.map((story) => this.active(story))}</div>
              </section>
            ),
            <section className="StoryChainSection">
              <h2 className="StoryChainSection-title">{t('page.library')}</h2>
              <p className="StoryChainSection-lead">{t('page.library_lead')}</p>
              {data.library.length ? (
                <div className="StoryChainShelf">
                  {data.library.map((story) => (
                    <Link className="StoryChainShelf-item" href={storyUrl(story)} aria-label={story.title}>
                      {Book({ story })}
                    </Link>
                  ))}
                </div>
              ) : (
                <p className="StoryChainEmpty">{t('page.library_empty')}</p>
              )}
              {data.more && (
                <Button className="Button StoryChainMore" loading={this.loadingMore} onclick={() => this.more()}>
                  {t('page.more')}
                </Button>
              )}
            </section>,
          ]
        )}
      </div>
    );
  }

  active(story: Story): Mithril.Children {
    const left = Date.parse(story.endsAt) - now();
    const href = storyUrl(story);

    return (
      <article className={`StoryChainActive StoryChain-cover--${story.cover}`}>
        <Link className="StoryChainActive-cover" href={href} tabindex={-1} aria-hidden="true">
          {Book({ story, size: 'small' })}
        </Link>
        <div className="StoryChainActive-body">
          <div className="StoryChainActive-meta">
            <span>{t('story.round', { round: story.round })}</span>
            {story.lastRound && <span className="StoryChainBadge">{t('story.final')}</span>}
            <span className="StoryChainActive-clock">
              <Icon name="far fa-clock" /> {left > 0 ? t('story.closes_in', { time: span(left) }) : t('story.closing')}
            </span>
          </div>
          <Link className="StoryChainActive-title" href={href}>
            {story.title}
          </Link>
          {story.last && (
            <blockquote className="StoryChainActive-line">
              <p>…{story.last.body}</p>
              <footer>{Person({ id: story.last.user })}</footer>
            </blockquote>
          )}
          <div className="StoryChainActive-foot">
            <span>
              {story.maxLines ? t('story.line_of', { n: story.lines, max: story.maxLines }) : t('story.lines', { count: story.lines })} ·{' '}
              {t('card.offered', { count: story.offered || 0 })}
            </span>
            <Link className="Button Button--primary StoryChainActive-cta" href={href}>
              {t('card.cta')}
            </Link>
          </div>
        </div>
      </article>
    );
  }
}
