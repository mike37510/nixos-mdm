(() => {
  const KEY = 'linuxmdm-theme';

  const resolved = () => {
    const stored = localStorage.getItem(KEY);
    if (stored === 'dark' || stored === 'light') return stored;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  };

  const apply = (theme) => {
    document.documentElement.setAttribute('data-theme', theme);
    const btn = document.getElementById('theme-toggle');
    if (btn) {
      const next = theme === 'dark' ? 'clair' : 'sombre';
      btn.setAttribute('aria-label', 'Passer en mode ' + next);
      btn.title = 'Mode ' + (theme === 'dark' ? 'sombre' : 'clair') + ' — cliquer pour basculer';
    }
  };

  const current = () => document.documentElement.getAttribute('data-theme') || resolved();

  apply(resolved());

  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = current() === 'dark' ? 'light' : 'dark';
    localStorage.setItem(KEY, next);
    apply(next);
  });

  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (localStorage.getItem(KEY) === null) {
      apply(resolved());
    }
  });
})();
