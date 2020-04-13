<?php

namespace Opengis\LaravelGeoserver\Exceptions;

use Exception;

class StyleContentNotFoundException extends Exception
{
    protected $message = 'Style content not found!';
}
