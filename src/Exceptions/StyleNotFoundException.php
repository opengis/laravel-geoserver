<?php

namespace Opengis\LaravelGeoserver\Exceptions;

use Exception;

class StyleNotFoundException extends Exception
{
    protected $message = 'Style not found!';
}
