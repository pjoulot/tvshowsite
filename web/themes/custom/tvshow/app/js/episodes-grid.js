(function ($, drupalSettings, Drupal) {
  Drupal.behaviors.episodeGrid = {
    attach: function (context, settings) {
      var $gridEpisodes = $('.view--all_episodes--block_1', context).isotope({
        itemSelector: '.c-episode-teaser',
        layoutMode: 'masonry',
        masonry: {
          "gutter": 25,
        },
        filter: '.saison-1',
      });
      $('.grid-filters [data-filter=".saison-1"]', context).addClass('is-checked');


      $('.grid-filters [data-filter]', context).on('click', function (e) {
        e.preventDefault();
        var filterValue = $(this).attr('data-filter');
        if (!$(this).hasClass('is-checked')) {
          $(this).parents('.grid-filters').find('*[data-filter]').removeClass('is-checked');
          $(this).addClass('is-checked');
        }

        $gridEpisodes.isotope({ filter: filterValue });
      });

      // Create the filter select for mobile.
      var select = '<select>';
      $('.grid-filters [data-filter]').each(function () {
        select += '<option value="' + $(this).attr('data-filter') + '">' + $(this).html() + '</option>';
      });
      select += '</select>';
      $('.grid-filters .filter-select', context).html(select);
      $('.grid-filters .filter-select select', context).on('change', function (e) {
        var filterValue = $(this).val();

        $gridEpisodes.isotope({ filter: filterValue });
      });
    }
  };
})(jQuery, drupalSettings, Drupal);
