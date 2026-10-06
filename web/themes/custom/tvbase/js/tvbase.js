/**
 * @file
 * Menu toggle, image lightbox and click-to-load videos. No dependencies
 * beyond Drupal's own behaviors and once().
 */
(function (Drupal, once) {
  document.documentElement.classList.add('js');

  Drupal.behaviors.tvshowMenu = {
    attach(context) {
      once('tv-menu', '.tv-nav__toggle', context).forEach((button) => {
        const menu = document.getElementById(button.getAttribute('aria-controls'));
        button.addEventListener('click', () => {
          const open = menu.classList.toggle('is-open');
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
      });
    },
  };

  Drupal.behaviors.tvshowVideo = {
    attach(context) {
      once('tv-video', '.video-embed[data-youtube]', context).forEach((box) => {
        const id = box.dataset.youtube;
        if (!/^[A-Za-z0-9_-]{11}$/.test(id)) {
          return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'video-embed__play';
        const label = box.dataset.title || 'Lire la vidéo';
        button.innerHTML = '<span class="video-embed__icon" aria-hidden="true"></span><span></span><span class="video-embed__note">La vidéo est chargée depuis YouTube au clic.</span>';
        button.children[1].textContent = label;
        button.addEventListener('click', () => {
          const frame = document.createElement('iframe');
          frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1';
          frame.title = label;
          frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
          frame.allowFullscreen = true;
          box.replaceChildren(frame);
        });
        box.replaceChildren(button);
      });
    },
  };

  Drupal.behaviors.tvshowGallery = {
    attach(context) {
      // Galleries, and any run of linked pictures inside article text.
      once('tv-gallery', '[data-tv-gallery], .tv-prose', context).forEach((list) => {
        const links = Array.from(list.querySelectorAll('a')).filter((a) => a.querySelector('img') && /\.(jpe?g|png|gif|webp)(\?.*)?$/i.test(a.getAttribute('href') || ''));
        if (!links.length) {
          return;
        }
        let dialog;
        let index = 0;
        const show = (i) => {
          index = (i + links.length) % links.length;
          const link = links[index];
          const img = dialog.querySelector('img');
          img.src = link.href;
          img.alt = link.querySelector('img').alt;
          dialog.querySelector('.tv-lightbox__count').textContent = index + 1 + ' / ' + links.length;
        };
        const build = () => {
          dialog = document.createElement('dialog');
          dialog.className = 'tv-lightbox';
          dialog.setAttribute('aria-label', 'Visionneuse d’images');
          dialog.innerHTML = '<div class="tv-lightbox__stage"><img alt=""></div>' +
            '<button class="tv-lightbox__close" type="button" aria-label="Fermer">✕</button>' +
            '<button class="tv-lightbox__prev" type="button" aria-label="Image précédente">←</button>' +
            '<button class="tv-lightbox__next" type="button" aria-label="Image suivante">→</button>' +
            '<p class="tv-lightbox__count" aria-live="polite"></p>';
          dialog.querySelector('.tv-lightbox__close').addEventListener('click', () => dialog.close());
          dialog.querySelector('.tv-lightbox__prev').addEventListener('click', () => show(index - 1));
          dialog.querySelector('.tv-lightbox__next').addEventListener('click', () => show(index + 1));
          dialog.addEventListener('click', (event) => {
            if (event.target === dialog || event.target.classList.contains('tv-lightbox__stage')) {
              dialog.close();
            }
          });
          dialog.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') { show(index - 1); }
            if (event.key === 'ArrowRight') { show(index + 1); }
          });
          document.body.appendChild(dialog);
        };
        links.forEach((link, i) => {
          link.addEventListener('click', (event) => {
            if (typeof HTMLDialogElement === 'undefined') {
              return;
            }
            event.preventDefault();
            if (!dialog) {
              build();
            }
            show(i);
            dialog.showModal();
          });
        });
      });
    },
  };
})(Drupal, once);
