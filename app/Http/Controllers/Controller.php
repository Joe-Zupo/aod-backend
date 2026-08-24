<?php

namespace App\Http\Controllers;

use App\Traits\Pagination;
use App\Traits\ResponseAPI;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests, Pagination, ResponseAPI;
}
