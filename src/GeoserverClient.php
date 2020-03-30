<?php

namespace Opengis\LaravelGeoserver;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Opengis\LaravelGeoserver\PostGisDataStore;
use Opengis\LaravelGeoserver\Exceptions\DatastoreNotFoundException;
use Opengis\LaravelGeoserver\Exceptions\WorkspaceNotFoundException;



class GeoserverClient
{
    private static String $baseUri;
    private static String $username;
    private static String $password;
    private static Http $client;


    function __construct(String $baseUri = null, String $username = null, String $password = null, Http $client = null)
    {
        is_null($client) ? self::$client = new Http : self::$client = $client;
        is_null($baseUri) && $baseUri = config('laravel-geoserver.uri');
        is_null($username) && $username = config('laravel-geoserver.username');
        is_null($password) && $password = config('laravel-geoserver.password');

        !Str::of($baseUri)->endsWith('/') && $baseUri = $baseUri . "/";

        self::$baseUri = $baseUri;
        self::$username = $username;
        self::$password = $password;

        $this->testConnection();
    }

    public static function create(...$params)
    {
        return new static(...$params);
    }

    private function testConnection()
    {

        self::$client::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri . 'rest/about/version')
            ->throw();
    }

    public static function getVersion(String $product = 'geoserver')
    {
        $resources = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->acceptJson()
            ->get(self::$baseUri . 'rest/about/version')
            ->throw()
            ->body())->about->resource;

        foreach ($resources as $resource) {

            if (Str::of($resource->{'@name'})->lower() == Str::of($product)->lower()) {

                return $resource->Version;
            }
        }
    }

    public static function workspaces()
    {

        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->acceptJson()
            ->get(self::$baseUri . 'rest/workspaces.json')
            ->throw()
            ->body());

        if (!is_null($response)) {
            if (isset($response->workspaces->workspace)) {

                return collect($response->workspaces->workspace)->map(function ($item) {
                    return self::workspace($item->name);
                });
            }
        }

        return new Collection;
    }

    public static function workspace(String $name)
    {

        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri . 'rest/workspaces/' . $name . ".json")
            ->throw()
            ->body());

        if (!is_null($response)) {
            return Workspace::create($response->workspace->name, $response->workspace->isolated, true);
        }
        throw new WorkspaceNotFoundException;
    }

    public static function workspaceExists(String $name)
    {

        $body = Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri . 'rest/workspaces/' . $name . ".json")
            ->body();

        return !Str::of($body)->contains('No such workspace');
    }

    public static function deleteWorkspace(Workspace $workspace)
    {
        try {
            if (self::workspaceExists($workspace->oldName)) {
                Http::withBasicAuth(self::$username, self::$password)
                    ->delete(self::$baseUri . 'rest/workspaces/' . $workspace->oldName . "?recurse=true")
                    ->throw();
            }
            return true;
        } catch (Exception $ex) {
            return false;
        }
    }

    public static function saveWorkspace(Workspace $workspace)
    {


        $data = ["workspace" => ["name" => $workspace->name, "isolated" => $workspace->isolated]];

        if (!$workspace->isSaved) {

            if (!self::workspaceExists($workspace->oldName)) {

                Http::withBasicAuth(self::$username, self::$password)
                    ->accept('text/html')
                    ->asJson()
                    ->post(self::$baseUri . 'rest/workspaces', $data)
                    ->throw();
            } else {

                Http::withBasicAuth(self::$username, self::$password)
                    ->accept('text/html')
                    ->asJson()
                    ->put(self::$baseUri . 'rest/workspaces/' . $workspace->oldName, $data)
                    ->throw();
            }
            return self::workspace($workspace->name);
        }
        return $workspace;
    }

    public static function datastores(Workspace $workspace)
    {

        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->acceptJson()
            ->get(self::$baseUri . 'rest/workspaces/' . $workspace->oldName . '/datastores.json')
            ->throw()
            ->body());

        if (!is_null($response)) {
            if (isset($response->dataStores->dataStore)) {
                return collect($response->dataStores->dataStore)->map(function ($item) use ($workspace) {
                    return self::datastore($workspace->oldName, $item->name);
                });
            }
        }

        return new Collection;
    }

    public static function datastoreExists(String $workspaceName, String $datastoreName)
    {

        $body = Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri . 'rest/workspaces/' . $workspaceName . '/datastores/' . $datastoreName . ".json")
            ->body();

        return !Str::of($body)->contains('No such datastore');
    }

    public static function datastore(String $workspaceName, String $datastoreName)
    {

        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri . 'rest/workspaces/' . $workspaceName . '/datastores/' . $datastoreName . ".json")
            ->throw()
            ->body());

        if (!is_null($response)) {
            $ds = $response->dataStore;

            if ($ds->type == 'PostGIS') {


                $entries = collect($ds->connectionParameters->entry)->mapWithKeys(function ($item) {
                    return [$item->{'@key'} => $item->{'$'}];
                })->toArray();

                $datastore = PostGisDataStore::create(
                    $ds->name,
                    self::workspace($ds->workspace->name),
                    $ds->description,
                    $entries["host"],
                    (int) $entries["port"],
                    $entries["database"],
                    $entries["schema"],
                    $entries["user"],
                    $entries["passwd"],
                    true
                );
                isset($entries["Evictor run periodicity"]) && $datastore->evictorRunPeriodicity = (int) $entries["Evictor run periodicity"];
                isset($entries["Max open prepared statements"]) && $datastore->maxOpenPreparedStatements = (int) $entries["Max open prepared statements"];
                isset($entries["encode functions"]) && $datastore->encodeFunctions = ($entries["encode functions"] == 'true' ? true : false);
                isset($entries["Primary key metadata table"]) && $datastore->primaryKeyMetadataTable = $entries["Primary key metadata table"];
                isset($entries["Batch insert size"]) && $datastore->batchInsertSize = (int) $entries["Batch insert size"];
                isset($entries["preparedStatements"]) && $datastore->preparedStatements = ($entries["preparedStatements"] == 'true' ? true : false);
                isset($entries["Loose bbox"]) && $datastore->looseBbox = ($entries["Loose bbox"] == 'true' ? true : false);
                isset($entries["SSL mode"]) && $datastore->sslMode = $entries["SSL mode"];
                isset($entries["Estimated extends"]) && $datastore->estimatedExtends = ($entries["Estimated extends"] == 'true' ? true : false);
                isset($entries["fetch size"]) && $datastore->fetchSize = (int) $entries["fetch size"];
                isset($entries["Expose primary keys"]) && $datastore->exposePrimaryKeys = ($entries["Expose primary keys"] == 'true' ? true : false);
                isset($entries["validate connections"]) && $datastore->validateConnections = ($entries["validate connections"] == 'true' ? true : false);
                isset($entries["Support on the fly geometry simplification"]) && $datastore->supportOnTheFlyGeometrySimplification = ($entries["Support on the fly geometry simplification"] == 'true' ? true : false);
                isset($entries["Connection timeout"]) && $datastore->connectionTimeout = (int) $entries["Connection timeout"];
                isset($entries["Callback factory"]) && $datastore->callbackFactory = $entries["Callback factory"];
                isset($entries["min connections"]) && $datastore->minConnections = (int) $entries["min connections"];
                isset($entries["max connections"]) && $datastore->maxConnections = (int) $entries["max connections"];
                isset($entries["Evictor tests per run"]) && $datastore->evictorTestsPerRun = (int) $entries["Evictor tests per run"];
                isset($entries["Test while idle"]) && $datastore->testWhileIdle = ($entries["Test while idle"] == 'true' ? true : false);
                isset($entries["Max connection idle time"]) && $datastore->maxConnectionIdleTime = (int) $entries["Max connection idle time"];

                $datastore->isPasswordOk = false;
                $datastore->isSaved = true;

                return $datastore;
            }
        }

        throw new DatastoreNotFoundException;
    }



    public static function saveDatastore(Datastore $datastore)
    {
        if ($datastore instanceof PostGisDataStore) {

            if (!$datastore->isSaved) {
                !self::workspaceExists($datastore->workspace->name) && $datastore->workspace = self::saveWorkspace($datastore->workspace);
                if (!self::datastoreExists($datastore->workspace->oldName, $datastore->oldName)) {
                    $data = [
                        "dataStore" =>
                        [
                            "name" => $datastore->name,
                            "description" => $datastore->description,
                            "type" => $datastore::TYPE,
                            "enabled" => $datastore->enabled,
                            "connectionParameters" =>
                            [
                                "entry" => array(
                                    ["@key" => "schema", "$" => $datastore->schema],
                                    ["@key" => "Evictor run periodicity", "$" => $datastore->evictorRunPeriodicity],
                                    ["@key" => "Max open prepared statements", "$" => $datastore->maxOpenPreparedStatements],
                                    ["@key" => "encode functions", "$" => $datastore->encodeFunctions],
                                    ["@key" => "Primary key metadata table", "$" => $datastore->primaryKeyMetadataTable],
                                    ["@key" => "Batch insert size", "$" => $datastore->batchInsertSize],
                                    ["@key" => "preparedStatements", "$" => $datastore->preparedStatements],
                                    ["@key" => "database", "$" => $datastore->database],
                                    ["@key" => "host", "$" => $datastore->host],
                                    ["@key" => "Loose bbox", "$" => $datastore->looseBbox],
                                    ["@key" => "SSL mode", "$" => $datastore->sslMode],
                                    ["@key" => "Estimated extends", "$" => $datastore->estimatedExtends],
                                    ["@key" => "fetch size", "$" => $datastore->fetchSize],
                                    ["@key" => "Expose primary keys", "$" => $datastore->exposePrimaryKeys],
                                    ["@key" => "validate connections", "$" => $datastore->validateConnections],
                                    ["@key" => "Support on the fly geometry simplification", "$" => $datastore->supportOnTheFlyGeometrySimplification],
                                    ["@key" => "Connection timeout", "$" => $datastore->connectionTimeout],
                                    ["@key" => "Callback factory", "$" => $datastore->callbackFactory],
                                    ["@key" => "port", "$" => $datastore->port],
                                    ["@key" => "passwd", "$" => $datastore->passwd],
                                    ["@key" => "min connections", "$" => $datastore->minConnections],
                                    ["@key" => "dbtype", "$" => $datastore::DBTYPE],
                                    ["@key" => "max connections", "$" => $datastore->maxConnections],
                                    ["@key" => "Evictor tests per run", "$" => $datastore->evictorTestsPerRun],
                                    ["@key" => "Test while idle", "$" => $datastore->testWhileIdle],
                                    ["@key" => "user", "$" => $datastore->user],
                                    ["@key" => "Max connection idle time", "$" => $datastore->maxConnectionIdleTime],

                                )
                            ]
                        ]
                    ];

                    Http::withBasicAuth(self::$username, self::$password)
                        ->accept('text/html')
                        ->asJson()
                        ->post(self::$baseUri . 'rest/workspaces/' . $datastore->workspace->name . '/datastores', $data)
                        ->throw();
                    return self::datastore($datastore->workspace->name, $datastore->name);
                } else {
                    $data = [
                        "dataStore" =>
                        [
                            "name" => $datastore->name,
                            "description" => $datastore->description,
                            "type" => $datastore::TYPE,
                            "enabled" => $datastore->enabled,
                            "connectionParameters" =>
                            [
                                "entry" => array(
                                    ["@key" => "passwd", "$" => $datastore->passwd],
                                    ["@key" => "schema", "$" => $datastore->schema],
                                    ["@key" => "Evictor run periodicity", "$" => $datastore->evictorRunPeriodicity],
                                    ["@key" => "Max open prepared statements", "$" => $datastore->maxOpenPreparedStatements],
                                    ["@key" => "encode functions", "$" => $datastore->encodeFunctions],
                                    ["@key" => "Primary key metadata table", "$" => $datastore->primaryKeyMetadataTable],
                                    ["@key" => "Batch insert size", "$" => $datastore->batchInsertSize],
                                    ["@key" => "preparedStatements", "$" => $datastore->preparedStatements],
                                    ["@key" => "database", "$" => $datastore->database],
                                    ["@key" => "host", "$" => $datastore->host],
                                    ["@key" => "Loose bbox", "$" => $datastore->looseBbox],
                                    ["@key" => "SSL mode", "$" => $datastore->sslMode],
                                    ["@key" => "Estimated extends", "$" => $datastore->estimatedExtends],
                                    ["@key" => "fetch size", "$" => $datastore->fetchSize],
                                    ["@key" => "Expose primary keys", "$" => $datastore->exposePrimaryKeys],
                                    ["@key" => "validate connections", "$" => $datastore->validateConnections],
                                    ["@key" => "Support on the fly geometry simplification", "$" => $datastore->supportOnTheFlyGeometrySimplification],
                                    ["@key" => "Connection timeout", "$" => $datastore->connectionTimeout],
                                    ["@key" => "Callback factory", "$" => $datastore->callbackFactory],
                                    ["@key" => "port", "$" => $datastore->port],
                                    ["@key" => "min connections", "$" => $datastore->minConnections],
                                    ["@key" => "dbtype", "$" => $datastore::DBTYPE],
                                    ["@key" => "max connections", "$" => $datastore->maxConnections],
                                    ["@key" => "Evictor tests per run", "$" => $datastore->evictorTestsPerRun],
                                    ["@key" => "Test while idle", "$" => $datastore->testWhileIdle],
                                    ["@key" => "user", "$" => $datastore->user],
                                    ["@key" => "Max connection idle time", "$" => $datastore->maxConnectionIdleTime],

                                )
                            ]
                        ]
                    ];


                    Http::withBasicAuth(self::$username, self::$password)
                        ->accept('text/html')
                        ->asJson()
                        ->put(self::$baseUri . 'rest/workspaces/' . $datastore->workspace->name . '/datastores/' . $datastore->oldName, $data)
                        ->throw();
                    return self::datastore($datastore->workspace->name, $datastore->name);
                }
            }
        }
        return $datastore;
    }



    public static function deleteDatastore(Datastore $datastore)
    {
        try {
            if (self::datastoreExists($datastore->workspace->oldName, $datastore->oldName)) {
                Http::withBasicAuth(self::$username, self::$password)
                    ->delete(self::$baseUri . 'rest/workspaces/' . $datastore->workspace->oldName . "/datastores/" . $datastore->oldName . "?recurse=true")
                    ->throw();
            }
            return true;
        } catch (Exception $ex) {
            return false;
        }
    }
}
