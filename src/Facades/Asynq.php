<?php

namespace AnasEqal\LaravelAsynq\Facades;

use Illuminate\Support\Facades\Facade;

class Asynq extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'asynq';
    }
}
