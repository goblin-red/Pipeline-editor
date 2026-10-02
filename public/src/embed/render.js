/* Самостоятельный renderer: функция переносится в HTML вместе со снимком данных.
   Без импортов, API, токенов, iframe и внешних библиотек. */
/* Подписи — из словаря страницы (window.GOBLIN_I18N): в редакторе его кладёт langScript,
   на чужих страницах — embed.php?renderer=1. Модуль без импортов, поэтому читает словарь сам. */
const tr = (key) => globalThis.GOBLIN_I18N?.dict?.[key] ?? key;

export function renderScheme(host, data) {
  const root = host.shadowRoot || host.attachShadow({ mode: 'open' });
  const style = document.createElement('style');
  style.textContent = `
    :host{display:block;min-width:0;color:#24292f;background:#fff;color-scheme:light}
    *{box-sizing:border-box}section{font:14px/1.5 system-ui,-apple-system,sans-serif;padding:16px}
    h2{font-size:18px;font-weight:600;margin:0 0 18px;overflow-wrap:anywhere}
    .viewport{overflow:auto}.canvas{position:relative;margin:auto}
    .card{position:absolute;border:1px solid #b9c1c8;border-radius:10px;padding:12px 14px;background:white;overflow-wrap:anywhere}
    .card.start{border-radius:24px}.card.decision{border-style:dashed}
    h3{font:600 14px/1.5 system-ui,sans-serif;margin:0}p{margin:6px 0 0;color:#59636e;white-space:pre-wrap}
    svg{position:absolute;inset:0;overflow:visible;pointer-events:none}
    svg text{font:12px system-ui,sans-serif;fill:#4b5563;stroke:white;stroke-width:4px;paint-order:stroke}
    .empty{color:#59636e}ul{padding-left:22px}details{margin-top:12px;color:#59636e;font-size:12px}
  `;
  const section = document.createElement('section');
  section.setAttribute('aria-label', tr('editor.embed.aria'));
  const viewport = document.createElement('div');
  viewport.className = 'viewport';
  const canvas = document.createElement('div');
  canvas.className = 'canvas';
  viewport.append(canvas);
  section.append(viewport);
  root.replaceChildren(style, section);
  if (!data.nodes.length) {
    const empty = document.createElement('p');
    empty.className = 'empty'; empty.textContent = tr('editor.embed.empty');
    section.append(empty); return () => {};
  }
  const ns = 'http://www.w3.org/2000/svg';
  const svgElement = (name, attrs = {}) => {
    const node = document.createElementNS(ns, name);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    return node;
  };
  const svg = svgElement('svg', { 'aria-hidden': 'true' });
  canvas.append(svg);
  const cards = new Map();
  const nodes = new Map(data.nodes.map(node => [node.id, node]));
  for (const node of data.nodes) {
    const card = document.createElement('article');
    card.className = 'card' + (node.start ? ' start' : node.type === 'decision' ? ' decision' : '');
    const name = document.createElement('h3');
    name.textContent = node.title || tr('editor.embed.untitled');
    card.append(name);
    if (node.description) {
      const text = document.createElement('p');
      text.textContent = node.description; card.append(text);
    }
    canvas.append(card); cards.set(node.id, card);
  }
  const edges = data.edges.filter(edge => nodes.has(edge.from) && nodes.has(edge.to));
  // Явные обратные стрелки не участвуют в определении рядов.
  const degree = new Map(data.nodes.map(node => [node.id, 0]));
  const next = new Map(data.nodes.map(node => [node.id, []]));
  for (const edge of edges) if (!edge.back && edge.from !== edge.to) {
    degree.set(edge.to, degree.get(edge.to) + 1);
    next.get(edge.from).push(edge.to);
  }
  const levels = new Map(data.nodes.map(node => [node.id, 0]));
  const queue = data.nodes.filter(node => !degree.get(node.id)).map(node => node.id);
  const placed = new Set();
  for (let i = 0; i < queue.length; i++) {
    const id = queue[i]; placed.add(id);
    for (const to of next.get(id)) {
      levels.set(to, Math.max(levels.get(to), levels.get(id) + 1));
      degree.set(to, degree.get(to) - 1);
      if (!degree.get(to)) queue.push(to);
    }
  }
  // Некорректный/непомеченный цикл тоже показываем, а не зависаем на нём.
  let last = Math.max(0, ...levels.values());
  for (const node of data.nodes) if (!placed.has(node.id)) levels.set(node.id, ++last);
  const groups = new Map();
  for (const node of data.nodes) {
    const level = levels.get(node.id);
    if (!groups.has(level)) groups.set(level, []);
    groups.get(level).push(node);
  }
  const ordered = [...groups.entries()].sort((a, b) => a[0] - b[0]);
  function draw() {
    const width = Math.max(240, viewport.clientWidth);
    const gutter = 26, gap = 24;
    const count = Math.max(1, Math.min(3, Math.floor((width - 2 * gutter + gap) / 244)));
    const cardWidth = Math.min(320, (width - 2 * gutter - gap * (count - 1)) / count);
    const positions = new Map();
    let y = 8, rowIndex = 0;
    for (const [, group] of ordered) {
      for (let start = 0; start < group.length; start += count) {
        const row = group.slice(start, start + count);
        const left = (width - row.length * cardWidth - (row.length - 1) * gap) / 2;
        let height = 0;
        row.forEach((node, column) => {
          const card = cards.get(node.id), x = left + column * (cardWidth + gap);
          card.style.width = `${cardWidth}px`;
          card.style.left = `${x}px`; card.style.top = `${y}px`;
          const h = card.offsetHeight;
          height = Math.max(height, h);
          positions.set(node.id, { x, y, w: cardWidth, h, row: rowIndex });
        });
        y += height + 62; rowIndex++;
      }
    }
    canvas.style.width = `${width}px`; canvas.style.height = `${y}px`;
    svg.setAttribute('width', width); svg.setAttribute('height', y);
    svg.replaceChildren();
    const defs = svgElement('defs');
    const marker = svgElement('marker', { id: 'arrow', markerWidth: 8, markerHeight: 8, refX: 7, refY: 4, orient: 'auto', markerUnits: 'userSpaceOnUse' });
    marker.append(svgElement('path', { d: 'M0 0 L8 4 L0 8 Z', fill: '#7b858f' }));
    defs.append(marker); svg.append(defs);
    edges.forEach((edge, index) => {
      const a = positions.get(edge.from), b = positions.get(edge.to);
      let d, tx, ty;
      if (b.row === a.row + 1 && !edge.back) {
        const ax = a.x + a.w / 2, ay = a.y + a.h, bx = b.x + b.w / 2, by = b.y - 3;
        const middle = (ay + by) / 2;
        d = `M${ax} ${ay} C${ax} ${middle} ${bx} ${middle} ${bx} ${by}`;
        tx = (ax + bx) / 2 + 7; ty = middle - 5;
      } else {
        // Длинные переходы и циклы ведём снаружи карточек.
        const side = index % 2 === 0, lane = side ? 8 + (index % 3) * 5 : width - 8 - (index % 3) * 5;
        const ax = a.x + a.w / 2, ay = a.y + a.h, bx = b.x + b.w / 2, by = b.y - 3;
        d = `M${ax} ${ay} V${ay + 17} H${lane} V${by - 17} H${bx} V${by}`;
        tx = lane + (side ? 5 : -5); ty = (ay + by) / 2;
      }
      svg.append(svgElement('path', { d, fill: 'none', stroke: '#7b858f', 'stroke-width': 1.4, 'marker-end': 'url(#arrow)' }));
      if (edge.label) {
        const label = svgElement('text', { x: tx, y: ty, 'text-anchor': tx > width / 2 ? 'end' : 'start' });
        label.textContent = edge.label; svg.append(label);
      }
    });
  }
  let frame = 0;
  const redraw = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(draw); };
  const observer = new ResizeObserver(redraw);
  observer.observe(viewport);
  for (const card of cards.values()) observer.observe(card);
  redraw();
  return () => { observer.disconnect(); cancelAnimationFrame(frame); };
}

export function schemeSnapshot(folder, elements) {
  const nodes = elements.filter(item => ['block', 'decision', 'gateway'].includes(item.type))
    .sort((a, b) => (a.style?.x || 0) - (b.style?.x || 0) || a.no - b.no)
    .map(item => ({ id: item.id, type: item.type, start: Boolean(item.props?.start),
      title: item.title || '', description: item.description || '' }));
  const ids = new Set(nodes.map(node => node.id));
  const edges = elements.filter(item => item.type === 'arrow' && ids.has(item.from) && ids.has(item.to))
    .map(item => ({ from: item.from, to: item.to, back: Boolean(item.back),
      label: item.title || (item.branch === 'yes' ? tr('editor.embed.yes') : item.branch === 'no' ? tr('editor.embed.no') : '') }));
  return { title: folder.name || tr('editor.embed.scheme'), nodes, edges };
}
