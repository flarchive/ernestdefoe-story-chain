import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import type Mithril from 'mithril';
import { t } from './api';
import IndexCard from './components/IndexCard';

export { default as extend } from './extend';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the sidebar link, the card on the discussion list and the notifications.
 * The pages and the story form are one chunk, fetched when first opened.
 */

const page = (name: 'StoriesPage' | 'StoryPage') => () => import('./pages').then(async (pages) => (await pages.loadCore(), { default: pages[name] }));

app.initializers.add('ernestdefoe/story-chain', () => {
  app.routes['story-chain'] = { path: '/stories', component: page('StoriesPage') };
  app.routes['story-chain.story'] = { path: '/stories/:id', component: page('StoryPage') };

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('storyChainCanView') || !app.forum.attribute('storyChainNav')) return;
    items.add(
      'story-chain',
      LinkButton.component(
        {
          href: app.route('story-chain'),
          icon: 'fas fa-feather-pointed',
          active: String(app.current.get('routeName') || '').startsWith('story-chain'),
        },
        t('nav')
      ),
      78
    );
  });

  extend('flarum/forum/components/IndexPage', 'contentItems', function (items: ItemList<Mithril.Children>) {
    const card = app.forum.attribute<any>('storyChainCard');
    // Only on the plain discussion list: not on a tag, a search or another filter.
    if (!card || app.current.get('routeName') !== 'index' || Object.keys(m.route.param() || {}).some((key) => key !== 'sort')) return;
    items.add('story-chain', <IndexCard card={card} />, 110);
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('storyChainCanView')) return;
    items.add('storyChainWon', { name: 'storyChainWon', icon: 'fas fa-feather-pointed', label: t('notification.won_preference') });
    items.add('storyChainRound', { name: 'storyChainRound', icon: 'fas fa-book-open', label: t('notification.round_preference') });
  });
});
