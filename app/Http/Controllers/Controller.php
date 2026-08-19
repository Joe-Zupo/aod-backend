<?php

namespace App\Http\Controllers;

use App\Traits\Pagination;
use App\Traits\ResponseAPI;

abstract class Controller
{
    use Pagination, ResponseAPI;
}
