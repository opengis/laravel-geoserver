<?php

namespace Opengis\LaravelGeoserver;

abstract class DataStore
{
    private Workspace $workspace;

    private String $name;
    private String $description;
    private $enabled = true;
    private $isSaved = false;
    private $oldName = '';

    private $forbiddenSet = ['forbiddenSet', 'workspace', 'oldName'];

    public function __construct(string $name, Workspace $workspace, string $description = null, $isSaved = false)
    {
        $this->workspace = $workspace;

        $this->name = $name;
        is_null($description) ? $this->description = 'Created by laravel-geoserver' : $this->description = $description;
        $this->oldName = $name;
        $this->isSaved = $isSaved;
    }

    public static function create(...$params)
    {
        return new static(...$params);
    }

    public function __get($property)
    {
        if (property_exists($this, $property)) {
            return $this->$property;
        }
    }

    public function __set($property, $value)
    {
        if (property_exists($this, $property) && ! in_array($property, $this->forbiddenSet)) {
            $property === 'name' && $this->oldName = $this->name;
            $this->isSaved = ! ($this->$property !== $value);
            $this->$property = $value;
        }

        return $this;
    }

    public function save()
    {
        return GeoserverClient::saveDatastore($this);
        // $this->oldName = $this->name;
        // $this->isSaved = true;

        // return $this;
    }

    public function delete()
    {
        $this->isSaved = ! GeoserverClient::deleteDatastore($this);
        $this->name = $this->oldName;

        return ! $this->isSaved;
    }
}
