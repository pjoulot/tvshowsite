/**
 * @file
 * Mega menu (large screens) and drawer menu (phones).
 *
 * Without JavaScript every section title is a plain link to its page and the
 * phone menu is shown open under the header.
 */
(function (Drupal, once) {
  Drupal.behaviors.sgpMegaMenu = {
    attach(context) {
      once('sgp-mega', '.sgp-nav', context).forEach((nav) => {
        const triggers = Array.from(nav.querySelectorAll('.sgp-nav__item[data-panel]'));
        const close = (except) => {
          triggers.forEach((trigger) => {
            if (trigger !== except) {
              trigger.setAttribute('aria-expanded', 'false');
              document.getElementById(trigger.dataset.panel).hidden = true;
            }
          });
        };
        triggers.forEach((trigger) => {
          const panel = document.getElementById(trigger.dataset.panel);
          trigger.setAttribute('role', 'button');
          trigger.setAttribute('aria-expanded', 'false');
          trigger.setAttribute('aria-controls', trigger.dataset.panel);
          trigger.addEventListener('click', (event) => {
            event.preventDefault();
            const open = trigger.getAttribute('aria-expanded') !== 'true';
            close(trigger);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
          });
          trigger.addEventListener('keydown', (event) => {
            if (event.key === ' ') {
              event.preventDefault();
              trigger.click();
            }
            // Tab from an open section goes into its panel.
            if (event.key === 'Tab' && !event.shiftKey && trigger.getAttribute('aria-expanded') === 'true') {
              const first = panel.querySelector('a');
              if (first) {
                event.preventDefault();
                first.focus();
              }
            }
          });
          // Leaving the panel by its last link goes on to the next section.
          panel.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab') {
              return;
            }
            const links = Array.from(panel.querySelectorAll('a'));
            if (event.shiftKey && document.activeElement === links[0]) {
              event.preventDefault();
              trigger.focus();
            }
            else if (!event.shiftKey && document.activeElement === links[links.length - 1]) {
              const next = trigger.closest('li').nextElementSibling;
              const target = next ? next.querySelector('a') : nav.querySelector('.sgp-search__input');
              if (target) {
                event.preventDefault();
                close();
                target.focus();
              }
            }
          });
        });
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            const open = triggers.find((trigger) => trigger.getAttribute('aria-expanded') === 'true');
            if (open) {
              close();
              open.focus();
            }
          }
        });
        document.addEventListener('click', (event) => {
          if (!nav.contains(event.target)) {
            close();
          }
        });
      });
    },
  };

  Drupal.behaviors.sgpDrawer = {
    attach(context) {
      once('sgp-drawer', '.sgp-menubutton', context).forEach((button) => {
        const drawer = document.getElementById(button.getAttribute('aria-controls'));
        const header = button.closest('.sgp-header');
        const label = button.querySelector('.sgp-menubutton__label');
        const toggle = (open) => {
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
          label.textContent = open ? 'Fermer' : 'Menu';
          drawer.classList.toggle('is-open', open);
          header.classList.toggle('is-open', open);
        };
        button.addEventListener('click', () => toggle(button.getAttribute('aria-expanded') !== 'true'));
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
            toggle(false);
            button.focus();
          }
        });
        // One section open at a time.
        const toggles = Array.from(drawer.querySelectorAll('button.sgp-drawer__toggle'));
        toggles.forEach((toggle) => {
          toggle.addEventListener('click', () => {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggles.forEach((other) => other.setAttribute('aria-expanded', 'false'));
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          });
        });
      });
    },
  };
})(Drupal, once);
