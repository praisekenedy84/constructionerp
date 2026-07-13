<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesByRole;

abstract class Controller
{
    use AuthorizesByRole;
}
