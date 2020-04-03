<?php

namespace Opengis\LaravelGeoserver\Exceptions;

use Exception;

class FeatureTypeNotFoundException extends Exception
{
    protected $message = 'Feature type not found!';
}
