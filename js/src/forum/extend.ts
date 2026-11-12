import Extend from 'flarum/common/extenders';
import { RoundNotification, WonNotification } from './notifications';

export default [new Extend.Notification().add('storyChainWon', WonNotification).add('storyChainRound', RoundNotification)];
