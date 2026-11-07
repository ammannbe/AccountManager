<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class EasterEggController extends Controller
{
    public function __invoke(): View
    {
        return view('easter-egg');
    }
}
