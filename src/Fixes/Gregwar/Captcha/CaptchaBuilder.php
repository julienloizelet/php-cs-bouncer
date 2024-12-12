<?php

namespace CrowdSecBouncer\Fixes\Gregwar\Captcha;

use Gregwar\Captcha\CaptchaBuilder as GregwarCaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use Gregwar\Captcha\PhraseBuilderInterface;

/**
 * Override to :
 * - fix "Implicitly marking parameter $builder as nullable is deprecated" warning on PHP  8.4
 *
 */
class CaptchaBuilder extends GregwarCaptchaBuilder
{
    public function __construct($phrase = null, ?PhraseBuilderInterface $builder = null)
    {
        if ($builder === null) {
            $this->builder = new PhraseBuilder;
        } else {
            $this->builder = $builder;
        }

        $this->phrase = is_string($phrase) ? $phrase : $this->builder->build($phrase);
    }
}
