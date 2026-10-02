/* One load per insertion: no polling or automatic retries. */
(() => {
  const script = document.currentScript;
  const host = script.previousElementSibling;
  if (!host) return;
  const base = new URL('.', script.src);
  const source = new URL(script.dataset.source, base);
  const lang = script.dataset.lang === 'en' ? 'en' : 'ru';
  if (lang === 'en') source.searchParams.set('lang', 'en');
  const words = lang === 'en' ? {
    loading: 'Loading the scheme…', unavailable: 'The scheme is unavailable', bad: 'Invalid response',
    failed: 'Could not load the scheme from Goblin. Refresh the page to try again.',
  } : {
    loading: 'Загрузка схемы…', unavailable: 'Схема недоступна', bad: 'Неверный ответ',
    failed: 'Не удалось загрузить схему из Goblin. Обновите страницу, чтобы повторить загрузку.',
  };
  const status = document.createElement('p');
  status.textContent = words.loading;
  host.append(status);
  Promise.all([
    import(new URL('embed.php?renderer=1&lang=' + lang, base).href),
    fetch(source, { credentials: 'omit', cache: 'no-store', signal: AbortSignal.timeout(15000) })
      .then(response => {
        if (!response.ok) throw new Error(words.unavailable);
        return response.json();
      }),
  ]).then(([{ renderScheme }, data]) => {
    if (!Array.isArray(data.nodes) || !Array.isArray(data.edges)) throw new Error(words.bad);
    if (!host.isConnected) return;
    status.remove();
    renderScheme(host, data);
  }).catch(() => {
    status.textContent = words.failed;
  });
})();
