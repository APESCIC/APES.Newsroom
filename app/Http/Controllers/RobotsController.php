<?php

namespace App\Http\Controllers;

use App\Services\Seo\RobotsTxtBuilder;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(RobotsTxtBuilder $robots): Response
    {
        return response($robots->render(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
