(() => {
  const svg = document.getElementById('document-graph');
  if (!svg) return;
  const original = svg.getAttribute('viewBox').split(' ').map(Number);
  let box = [...original];
  const lite = svg.dataset.lite === '1';
  let frame = 0;
  const draw = () => {
    if (frame) return;
    frame = requestAnimationFrame(() => { frame = 0; svg.setAttribute('viewBox', box.join(' ')); place(); });
  };
  const nodes = [...svg.querySelectorAll('.graph-node')];
  const edges = [...svg.querySelectorAll('.doc-edge')];
  const initial = svg.dataset.focus === '0' ? null : svg.dataset.focus;
  const highlight = id => {
    const connected = new Set(id ? [id] : []);
    for (const edge of edges) {
      const active = id && (edge.dataset.source === id || edge.dataset.target === id);
      edge.classList.toggle('active-edge', !!active);
      edge.classList.toggle('dimmed', !!id && !active);
      if (active) { connected.add(edge.dataset.source); connected.add(edge.dataset.target); }
    }
    for (const node of nodes) {
      node.classList.toggle('selected-node', node.dataset.pageId === id);
      node.classList.toggle('dimmed', !!id && !connected.has(node.dataset.pageId));
    }
  };
  /* Щелчок по шарику не уводит со страницы: закрепляет подсветку его связей и открывает плашку
     «Подробнее» — переход в статью только по ней. Пустое место или Esc снимают закрепление. */
  let pinned = initial;
  const more = document.createElement('a');
  more.className = 'graph-more';
  more.hidden = true;
  svg.parentElement.append(more);
  const place = () => {
    const node = nodes.find((one) => one.dataset.pageId === pinned);
    if (!node || more.hidden) return;
    const dot = node.querySelector('circle').getBoundingClientRect();
    const frameBox = svg.parentElement.getBoundingClientRect();
    const x = dot.left + dot.width / 2 - frameBox.left;
    const y = dot.top + dot.height / 2 - frameBox.top;
    // У верхнего края плашка встаёт под шариком; вбок за рамку не выходит.
    more.classList.toggle('below', y < more.offsetHeight + 40);
    const half = more.offsetWidth / 2 + 8;
    more.style.left = Math.max(half, Math.min(frameBox.width - half, x)) + 'px';
    more.style.top = y + 'px';
  };
  const pin = (node) => {
    pinned = node ? node.dataset.pageId : null;
    if (!lite) highlight(pinned);
    more.hidden = !node;
    if (!node) return;
    place();
    more.href = node.getAttribute('href');
    more.textContent = svg.dataset.more + ' ' + node.getAttribute('aria-label');
    requestAnimationFrame(place);   // размер плашки известен после отрисовки текста
  };
  for (const node of nodes) {
    node.addEventListener('click', (event) => { event.preventDefault(); pin(node); });
    if (lite) continue;
    node.addEventListener('pointerenter', () => highlight(node.dataset.pageId));
    node.addEventListener('pointerleave', () => highlight(pinned));
    node.addEventListener('focus', () => highlight(node.dataset.pageId));
    node.addEventListener('blur', () => highlight(pinned));
  }
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !more.hidden) pin(null); });
  if (!lite) highlight(initial);
  const zoom = (factor, clientX, clientY) => {
    let anchorX = box[0] + box[2] / 2;
    let anchorY = box[1] + box[3] / 2;
    const matrix = svg.getScreenCTM();
    if (matrix && Number.isFinite(clientX) && Number.isFinite(clientY)) {
      const point = svg.createSVGPoint();
      point.x = clientX;
      point.y = clientY;
      const anchor = point.matrixTransform(matrix.inverse());
      anchorX = anchor.x;
      anchorY = anchor.y;
    }
    const width = Math.max(Math.min(200, original[2] / 8), Math.min(original[2] * 2, box[2] * factor));
    const height = width * original[3] / original[2];
    const ratio = width / box[2];
    box = [anchorX - (anchorX - box[0]) * ratio, anchorY - (anchorY - box[1]) * ratio, width, height];
    draw();
  };
  // Chromium/Firefox передают щипок трекпада как wheel с ctrlKey.
  // Масштабируем относительно указателя, сохраняя под ним точку карты.
  let gestureActive = false;
  let gestureScale = 1;
  svg.addEventListener('wheel', event => {
    if (!event.ctrlKey) return;
    event.preventDefault();
    if (gestureActive) return;
    drag = null;
    const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? svg.clientHeight : 1;
    const delta = Math.max(-100, Math.min(100, event.deltaY * unit));
    zoom(Math.exp(delta * .01), event.clientX, event.clientY);
  }, { passive: false });
  // Safari предоставляет собственные события жеста с накопленным scale.
  svg.addEventListener('gesturestart', event => {
    event.preventDefault();
    drag = null;
    gestureActive = true;
    gestureScale = Number.isFinite(event.scale) && event.scale > 0 ? event.scale : 1;
  }, { passive: false });
  svg.addEventListener('gesturechange', event => {
    if (!gestureActive) return;
    event.preventDefault();
    if (!Number.isFinite(event.scale) || event.scale <= 0) return;
    zoom(gestureScale / event.scale, event.clientX, event.clientY);
    gestureScale = event.scale;
  }, { passive: false });
  const endGesture = event => {
    if (!gestureActive) return;
    event.preventDefault();
    gestureActive = false;
    gestureScale = 1;
  };
  document.addEventListener('gestureend', endGesture, { passive: false });
  window.addEventListener('blur', () => { gestureActive = false; gestureScale = 1; drag = null; });
  document.querySelectorAll('[data-zoom]').forEach(button => button.addEventListener('click', () => {
    if (button.dataset.zoom === 'reset') { box = [...original]; draw(); }
    else zoom(button.dataset.zoom === 'in' ? .8 : 1.25);
  }));
  document.getElementById('graph-hierarchy').addEventListener('change', event => {
    svg.querySelector('.hierarchy-edges').style.display = event.target.checked ? '' : 'none';
  });
  let drag = null;
  svg.addEventListener('pointerdown', event => {
    if (event.button !== 0 || event.target.closest('a')) return;
    drag = { x: event.clientX, y: event.clientY, box: [...box] };
    svg.setPointerCapture(event.pointerId);
  });
  svg.addEventListener('pointerup', event => {
    if (drag && Math.hypot(event.clientX - drag.x, event.clientY - drag.y) < 4) pin(null);
  });
  svg.addEventListener('pointermove', event => {
    if (!drag) return;
    const rect = svg.getBoundingClientRect();
    const scale = Math.max(drag.box[2] / rect.width, drag.box[3] / rect.height);
    box[0] = drag.box[0] - (event.clientX - drag.x) * scale;
    box[1] = drag.box[1] - (event.clientY - drag.y) * scale;
    draw();
  });
  for (const event of ['pointerup', 'pointercancel', 'lostpointercapture']) svg.addEventListener(event, () => { drag = null; });
})();
