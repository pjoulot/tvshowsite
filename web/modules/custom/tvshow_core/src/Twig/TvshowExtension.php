<?php

namespace Drupal\tvshow_core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Small template helpers.
 */
class TvshowExtension extends AbstractExtension {

  public function getFunctions(): array {
    return [
      new TwigFunction('tv_pad', fn($number, int $length = 2) => str_pad((string) (int) $number, $length, '0', STR_PAD_LEFT)),
    ];
  }

}
