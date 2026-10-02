/* Экспорт только иллюстрации: заголовки, описания и связи. Никаких ключей проекта. */
import * as api from 'goblin/api/client.js';
import { renderScheme, schemeSnapshot } from 'goblin/embed/render.js';
import { el, button, note } from 'goblin/panel/parts.js';
import { toast } from 'goblin/shell/topbar.js';
import { t, lang } from 'goblin/core/i18n.js';

export async function openEmbed(folder) {
  let result;
  try { result = await api.get('folder.get', { folder: folder.id }); }
  catch { return; } // api уже показал ошибку.
  const data = schemeSnapshot(result.folder, result.scheme);
  let access;
  try { access = await api.get('folder.embed', { folder: folder.id }); } catch { return; }
  const base = new URL('.', document.baseURI);
  const src = new URL('embed.js', base).href;
  const source = new URL(access.source, base).href;
  const attr = value => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const code = `<div class="goblin-scheme"></div>\n<script src="${attr(src)}" data-source="${attr(source)}" data-lang="${lang}"></script>`;
  const dialog = el('dialog', 'dialog dialog-wide');
  dialog.style.cssText = 'max-height:90dvh;overflow:auto;width:min(900px,94vw)';
  const heading = el('h2', 'dialog-title', t('editor.embed.title', { name: data.title }));
  const hint = note(t('editor.embed.hint'));
  const preview = el('div');
  preview.style.cssText = 'max-height:42vh;overflow:auto;border:1px solid var(--line);border-radius:10px;margin:12px 0';
  const area = el('textarea', 'dialog-textarea');
  area.style.height = '160px'; area.readOnly = true; area.value = code;
  area.setAttribute('aria-label', t('editor.embed.code_label'));
  area.spellcheck = false;
  const foot = el('div', 'dialog-foot');
  const copy = button(t('editor.info.copy_code'), async () => {
    try { await navigator.clipboard.writeText(code); toast(t('editor.info.copied')); }
    catch { area.focus(); area.select(); toast(t('editor.info.selected')); }
  });
  foot.append(copy, button(t('common.close'), () => dialog.close()));
  dialog.append(heading, hint, preview, area, foot);
  document.body.append(dialog);
  dialog.showModal();
  const dispose = renderScheme(preview, data);
  dialog.addEventListener('close', () => { dispose(); dialog.remove(); }, { once: true });
}
