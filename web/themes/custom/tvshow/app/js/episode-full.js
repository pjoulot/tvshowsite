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
          })(jQuery);
        }
      }, 100);
    }
  };
})(jQuery, drupalSettings, Drupal);
