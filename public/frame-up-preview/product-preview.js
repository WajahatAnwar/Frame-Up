(async () => {
  if (customElements.get('frame-up-preview')) return;
  const host = document.querySelector('frame-up-preview');
  if (!host) return;
  try {
    const { default: Preview } = await import(host.dataset.runtimeUrl);
    customElements.define('frame-up-preview', Preview);
  } catch (error) {
    console.warn('Frame Up preview could not be loaded.', error);
  }
})();
