(function ($, drupalSettings, Drupal) {
  Drupal.behaviors.hamburgerMenu = {
    attach: function (context, settings) {
      $('.hamburger', context).on('click', function (e) {
        e.preventDefault();
        $('.hamburger').toggleClass("is-active");
        $('.main-menu-area').toggleClass("active");
      });
    }
  };
}) (jQuery, drupalSettings, Drupal);
