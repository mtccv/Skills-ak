/* Progressive enhancement: both language panels remain available without JavaScript. */
document.querySelectorAll('[data-aish-language-tabs]').forEach((group) => {
  const buttons = [...group.querySelectorAll('[role="tab"]')];
  const activate = (button) => {
    buttons.forEach((tab) => {
      const active = tab === button;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      const panel = document.getElementById(tab.getAttribute('aria-controls'));
      if (panel) panel.hidden = !active;
    });
    window.dispatchEvent(new Event('resize'));
  };
  buttons.forEach((button, index) => {
    button.addEventListener('click', () => activate(button));
    button.addEventListener('keydown', (event) => {
      const destinations = { ArrowRight: (index + 1) % buttons.length, ArrowLeft: (index + buttons.length - 1) % buttons.length, Home: 0, End: buttons.length - 1 };
      if (event.key in destinations) {
        event.preventDefault();
        const target = buttons[destinations[event.key]];
        activate(target);
        target.focus();
      }
    });
  });
  activate(buttons.find((button) => button.dataset.default === '1') || buttons[0]);
});
