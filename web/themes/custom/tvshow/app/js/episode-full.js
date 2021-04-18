(function ($, drupalSettings, Drupal) {
  Drupal.behaviors.episodeFull = {
    attach: function (context, settings) {
      var checkReadyState = setInterval(() => {
        if (document.readyState === "complete") {
          clearInterval(checkReadyState);
          (function ($) {
            var $gridEpisodes = $('#episode-promo', context).masonry({
              itemSelector: '.lightgallery li',
              gutter: 15,
              columnWidth: 180,
              percentPosition: true,
            });

            var $gridTrailers = $('#episode-trailers', context).masonry({
              itemSelector: '.lightgallery li',
              gutter: 15,
              columnWidth: 400,
              percentPosition: true,
            });
          })(jQuery);

          $('#episode-tab a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            // Throw resize to reconstruct the masonry correctly.
            window.dispatchEvent(new Event('resize'));
          })
        }
      }, 100);
    }
  };
})(jQuery, drupalSettings, Drupal);
