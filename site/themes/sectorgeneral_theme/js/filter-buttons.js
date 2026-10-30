/**
 * @file
 * Applies a listing filter as soon as one of its buttons is chosen.
 */
((Drupal, once) => {
  Drupal.behaviors.sectorgeneralFilterButtons = {
    attach(context) {
      once('filter-buttons', '.views-exposed-form--buttons', context).forEach((form) => {
        form.addEventListener('change', (event) => {
          if (event.target.matches('.filter-buttons input[type="radio"]')) {
            // Through the Apply button, so Views' own (and any AJAX) handling
            // of the submission applies.
            const submit = form.querySelector('.form-actions [type="submit"]');
            form.requestSubmit(submit);
          }
        });
      });
    },
  };
})(Drupal, once);
