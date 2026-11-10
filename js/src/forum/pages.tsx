import app from 'flarum/forum/app';

/*
 * Everything a member only needs once they open Story Chain: the pages and
 * the story form. One chunk, fetched on first use; forum.js stays small.
 */

declare const flarum: any;

/** Core page parts, loaded through the registry before a page draws (they are lazy in Flarum 2). */
export let PageStructure: any;
export let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  if (PageStructure) return;
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

export function openStoryForm(attrs: Record<string, any>): void {
  app.modal.show(StoryModal, attrs);
}

import StoryModal from './components/StoryModal';
export { default as StoriesPage } from './components/StoriesPage';
export { default as StoryPage } from './components/StoryPage';
