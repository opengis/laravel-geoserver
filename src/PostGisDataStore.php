<?php

namespace Opengis\LaravelGeoserver;

class PostGisDataStore extends DataStore
{
    public const TYPE = 'PostGIS';
    public const DBTYPE = 'postgis';

    private $evictorRunPeriodicity = 300;
    private $maxOpenPreparedStatements = 50;
    private $encodeFunctions = true;
    private $batchInsertSize = 1;
    private $schema = '';
    private $preparedStatements = false;
    private $database = '';
    private $host = '';
    private $looseBbox = true;
    private $sslMode = 'DISABLE';
    private $estimatedExtends = true;
    private $fetchSize = 1000;
    private $exposePrimaryKeys = true;
    private $primaryKeyMetadataTable = '';
    private $validateConnections = true;
    private $supportOnTheFlyGeometrySimplification = true;
    private $connectionTimeout = 20;
    private $callbackFactory = '';
    private $port = 5432;
    private $passwd = '';
    private $minConnections = 1;
    private $maxConnections = 10;
    private $evictorTestsPerRun = 3;
    private $testWhileIdle = true;
    private $user = '';
    private $maxConnectionIdleTime = 300;

    public function __construct(string $name, Workspace $workspace, string $description = null, string $host, int $port, string $database, string $schema, string $user, string $passwd, $isSaved = false)
    {
        parent::__construct($name, $workspace, $description, $isSaved);

        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->schema = $schema;
        $this->user = $user;
        $this->passwd = $passwd;
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

        return parent::__get($property);
    }

    public function __set($property, $value)
    {
        if (property_exists($this, $property)) {
            $this->isSaved = !($this->$property !== $value);
            $this->$property = $value;

            return $this;
        }

        return parent::__set($property, $value);
    }

    public function layers()
    {
        return GeoserverClient::featureTypes($this);
    }
}
