<?php

namespace Opengis\LaravelGeoserver\Exceptions;

use Exception;

class TableNotFoundException extends Exception
{
    protected $message = 'Table not found!';
}
