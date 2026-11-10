import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { COVERS, HOURS, call, storyUrl, t, type Story } from '../api';

interface Attrs extends IFormModalAttrs {
  /** Editing: the story, and what to do once saved. */
  story?: Story;
  onsave?: () => void;
}

const ENDS = ['', '10', '20', '30', '50', '100'];
const LENGTHS = ['100', '140', '200', '280', '400', '500'];

/** Start a story from its opening line, or (moderators) edit one. */
export default class StoryModal extends FormModal<Attrs> {
  title_ = Stream('');
  opening = Stream('');
  blurb = Stream('');
  cover = Stream(COVERS[0]);
  hours = Stream('24');
  ends = Stream('20');
  length = Stream('200');

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const story = this.attrs.story;
    if (story) {
      this.title_(story.title);
      this.blurb(story.blurb || '');
      this.cover(story.cover);
      this.hours(String(story.roundHours));
      this.ends(story.maxLines ? String(story.maxLines) : '');
      this.length(String(story.maxLength));
    } else {
      this.cover(COVERS[Math.floor(Math.random() * COVERS.length)]);
    }
  }

  className() {
    return 'StoryChainModal Modal--medium';
  }

  title() {
    return t(this.attrs.story ? 'form.title_edit' : 'form.title_new');
  }

  content() {
    const story = this.attrs.story;
    const ends = [...new Set([...ENDS, this.ends()])].sort((a, b) => Number(a || 0) - Number(b || 0));
    const lengths = [...new Set([...LENGTHS, this.length()])].sort((a, b) => Number(a) - Number(b));

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="sc-title">{t('form.title')}</label>
            <input id="sc-title" className="FormControl" maxlength={120} bidi={this.title_} required />
          </div>
          {!story && (
            <div className="Form-group">
              <label for="sc-opening">{t('form.opening')}</label>
              <p className="helpText">{t('form.opening_help')}</p>
              <textarea
                id="sc-opening"
                className="FormControl StoryChain-serif"
                rows={3}
                maxlength={Number(this.length())}
                required
                value={this.opening()}
                oninput={(e: InputEvent) => this.opening((e.target as HTMLTextAreaElement).value.replace(/\s*\n\s*/g, ' '))}
              />
              <p className="StoryChainCounter">
                {this.opening().trim().length} / {this.length()}
              </p>
            </div>
          )}
          <div className="Form-group">
            <label for="sc-blurb">{t('form.blurb')}</label>
            <p className="helpText">{t('form.blurb_help')}</p>
            <input id="sc-blurb" className="FormControl" maxlength={300} bidi={this.blurb} />
          </div>
          <div className="Form-group">
            <label>{t('form.cover')}</label>
            <div className="StoryChainSwatches" role="radiogroup" aria-label={extractText(t('form.cover'))}>
              {COVERS.map((cover) => (
                <button
                  type="button"
                  role="radio"
                  aria-checked={this.cover() === cover ? 'true' : 'false'}
                  aria-label={extractText(t(`covers.${cover}`))}
                  title={extractText(t(`covers.${cover}`))}
                  className={classList('StoryChainSwatch', `StoryChain-cover--${cover}`, { active: this.cover() === cover })}
                  onclick={() => this.cover(cover)}
                />
              ))}
            </div>
          </div>
          <div className="StoryChainModal-row">
            <div className="Form-group">
              <label>{t('form.round')}</label>
              <Select
                value={this.hours()}
                options={Object.fromEntries(HOURS.map((h) => [String(h), t(`hours.h${h}`)]))}
                onchange={(value: string) => this.hours(value)}
              />
            </div>
            <div className="Form-group">
              <label>{t('form.end')}</label>
              <Select
                value={this.ends()}
                options={Object.fromEntries(ends.map((n) => [n, n ? t('form.lines_count', { count: n }) : t('form.end_open')]))}
                onchange={(value: string) => this.ends(value)}
              />
            </div>
            <div className="Form-group">
              <label>{t('form.length')}</label>
              <Select
                value={this.length()}
                options={Object.fromEntries(lengths.map((n) => [n, t('form.length_count', { count: n })]))}
                onchange={(value: string) => this.length(value)}
              />
            </div>
          </div>
          {story && story.status === 'active' && <p className="helpText">{t('form.timing_note')}</p>}
          <div className="Form-group">
            <Button type="submit" className="Button Button--primary Button--block" loading={this.loading}>
              {t(story ? 'form.submit_edit' : 'form.submit_new')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const story = this.attrs.story;
    const body: Record<string, any> = {
      title: this.title_(),
      blurb: this.blurb(),
      cover: this.cover(),
      roundHours: Number(this.hours()),
      maxLines: this.ends() ? Number(this.ends()) : null,
      maxLength: Number(this.length()),
    };
    if (!story) body.opening = this.opening();

    this.loading = true;
    call<{ id: number; slug: string }>(story ? 'PATCH' : 'POST', story ? `/${story.id}` : '', body)
      .then((saved) => {
        this.hide();
        if (story) this.attrs.onsave?.();
        else m.route.set(storyUrl(saved));
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
