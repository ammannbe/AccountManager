<?php

namespace App\Support;

/** Target of the "All rights reserved." link in the mail footer: the page of this app. */
class EasterEgg
{
    public static function url(): string
    {
        return route('easter-egg');
    }
}
