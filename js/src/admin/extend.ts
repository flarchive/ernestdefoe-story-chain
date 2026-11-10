import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

const t = (key: string) => app.translator.trans(`ernestdefoe-story-chain.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-story-chain.${key}`;

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(() => ({ setting: setting('index_card'), type: 'boolean', label: t('settings.card'), help: t('settings.card_help') }), 95)
    .setting(() => ({ setting: setting('live_votes'), type: 'boolean', label: t('settings.live_votes'), help: t('settings.live_votes_help') }), 90)
    .setting(
      () => ({ setting: setting('show_authors'), type: 'boolean', label: t('settings.show_authors'), help: t('settings.show_authors_help') }),
      85
    )
    .setting(() => ({ setting: setting('no_repeat'), type: 'boolean', label: t('settings.no_repeat'), help: t('settings.no_repeat_help') }), 80)
    .permission(() => ({ permission: 'storyChain.view', icon: 'fas fa-book-open', label: t('permissions.view'), allowGuest: true }), 'view', 60)
    .permission(() => ({ permission: 'storyChain.write', icon: 'fas fa-feather-pointed', label: t('permissions.write') }), 'reply', 60)
    .permission(() => ({ permission: 'storyChain.vote', icon: 'far fa-heart', label: t('permissions.vote') }), 'reply', 59)
    .permission(() => ({ permission: 'storyChain.start', icon: 'fas fa-book', label: t('permissions.start') }), 'start', 60)
    .permission(() => ({ permission: 'storyChain.moderate', icon: 'fas fa-gavel', label: t('permissions.moderate') }), 'moderate', 60),
];
