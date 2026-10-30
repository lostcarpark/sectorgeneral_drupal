/**
 * @file
 * Opens and closes the header search, like the menu's drop-downs: the button
 * toggles it, and Escape, clicking elsewhere or tabbing out closes it.
 */
((Drupal, once) => {
  Drupal.behaviors.sectorgeneralSiteSearch = {
    attach(context) {
      once('site-search', '[data-site-search]', context).forEach((search) => {
        const toggle = search.querySelector('.site-search__toggle');
        const input = search.querySelector('input[type="search"]');
        const isOpen = () => toggle.getAttribute('aria-expanded') === 'true';
        const setOpen = (open) => {
          toggle.setAttribute('aria-expanded', String(open));
          if (open) {
            input?.focus();
          }
        };

        toggle.addEventListener('click', () => setOpen(!isOpen()));
        search.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && isOpen()) {
            event.stopPropagation();
            setOpen(false);
            toggle.focus();
          }
        });
        search.addEventListener('focusout', (event) => {
          if (event.relatedTarget && !search.contains(event.relatedTarget)) {
            setOpen(false);
          }
        });
        document.addEventListener('click', (event) => {
          if (isOpen() && !search.contains(event.target)) {
            setOpen(false);
          }
        });
      });
    },
  };
})(Drupal, once);
