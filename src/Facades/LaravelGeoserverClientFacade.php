<?php

namespace Opengis\LaravelGeoserver\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Opengis\LaravelGeoserver\Skeleton\SkeletonClass
 */
class LaravelGeoserverClientFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'laravel-geoserver-client';
    }
}
