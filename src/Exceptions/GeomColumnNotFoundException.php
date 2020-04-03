<?php

namespace Opengis\LaravelGeoserver\Exceptions;

use Exception;

class GeomColumnNotFoundException extends Exception
{
    protected $message = 'Geometry or geography column not found!';
}
