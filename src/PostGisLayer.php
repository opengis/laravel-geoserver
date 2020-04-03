<?php

namespace Opengis\LaravelGeoserver;

class PostGisLayer
{
    private $isSaved = false;
    private $name = '';
    private $oldName = '';
    private $tableName = '';
    private $title = '';
    private $datastore;

    public function __construct(String $name, String $tableName, PostGisDataStore $datastore, $isSaved = false)
    {
        $this->name = $name;
        $this->oldName = $name;
        $this->tableName = $tableName;
        $this->datastore = $datastore;
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
        if (property_exists($this, $property)) {
            $property === 'name' && $this->oldName = $this->name;
            $this->isSaved = !($this->$property !== $value);
            $this->$property = $value;
        }

        return $this;
    }

    public function save()
    {
        return GeoserverClient::saveFeatureType($this);
    }

    public function delete()
    {
        $this->isSaved = !GeoserverClient::deleteFeatureType($this);
        $this->name = $this->oldName;

        return !$this->isSaved;
    }
}
