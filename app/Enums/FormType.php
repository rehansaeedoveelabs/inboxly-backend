<?php

namespace App\Enums;

enum FormType: string
{
    case Popup = 'popup';
    case Embedded = 'embedded';
    case LandingPage = 'landing_page';
    case Flyout = 'flyout';
    case Fullscreen = 'fullscreen';
}
