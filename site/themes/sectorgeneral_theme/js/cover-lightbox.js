/**
 * @file
 * Opens a page's cover full size over the page instead of on its own.
 *
 * Uses the browser's own <dialog>: Escape closes it, focus stays inside while
 * it's open and returns to the cover afterwards. A click anywhere - on the
 * dimmed page or the image - closes it too. Without JavaScript, the cover
 * stays a plain link to the image file.
 */
((Drupal, once) => {
  let dialog;

  function getDialog() {
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.className = 'cover-lightbox';
      dialog.innerHTML = `<button type="button" class="cover-lightbox__close">
        <span aria-hidden="true">&times;</span>
        <span class="visually-hidden">${Drupal.t('Close')}</span>
      </button><img class="cover-lightbox__image" alt="">`;
      dialog.addEventListener('click', () => dialog.close());
      dialog.addEventListener('close', () => {
        // Don't keep a large image in memory, or show it next time.
        dialog.querySelector('img').removeAttribute('src');
      });
      document.body.append(dialog);
    }
    return dialog;
  }

  Drupal.behaviors.sectorgeneralCoverLightbox = {
    attach(context) {
      // The page's own cover, where it links to the image file (thumbnails in
      // tables link to their pages, and stay that way).
      const covers = '.book-header__cover a[href]:has(img), .node--view-mode-full .field--name-field-cover a[href]:has(img)';
      once('cover-lightbox', covers, context).forEach((link) => {
        if (!/\.(jpe?g|png|gif|webp|avif)$/i.test(link.pathname)) {
          return;
        }
        link.addEventListener('click', (event) => {
          // Let new-tab and new-window clicks through.
          if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
          }
          event.preventDefault();
          const box = getDialog();
          const image = box.querySelector('img');
          const thumbnail = link.querySelector('img');
          image.src = link.href;
          image.alt = thumbnail.alt;
          box.setAttribute('aria-label', thumbnail.alt || Drupal.t('Cover image'));
          box.showModal();
        });
      });
    },
  };
})(Drupal, once);
