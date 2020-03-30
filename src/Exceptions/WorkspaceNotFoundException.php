<?php

namespace Opengis\LaravelGeoserver\Exceptions;

use Exception;

class WorkspaceNotFoundException extends Exception
{
    protected $message = 'Workspace not found!';
}
