<?php

namespace Opengis\LaravelGeoserver;

class Style
{
    private $isSaved = false;
    private $name = '';
    private $oldName = '';
    private $styleContent = '';
    private $workspace;
    private $forbiddenSet = ['forbiddenSet', 'isSaved', 'oldName'];

    public function __construct(string $name, $isSaved = false)
    {
        $this->name = $name;
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
        if (property_exists($this, $property) && !in_array($property, $this->forbiddenSet)) {
            $property === 'name' && $this->oldName = $this->name;
            $this->isSaved = !($this->$property !== $value);
            $this->$property = $value;
        }

        return $this;
    }

    // public function save()
    // {
    //     return GeoserverClient::saveWorkspace($this);
    // }

    // public function delete()
    // {
    //     $this->isSaved = !GeoserverClient::deleteWorkspace($this);
    //     $this->name = $this->oldName;

    //     return !$this->isSaved;
    // }

    // public function datastores()
    // {
    //     return GeoserverClient::datastores($this);
    // }
}
