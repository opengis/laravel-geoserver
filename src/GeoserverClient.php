<?php

namespace Opengis\LaravelGeoserver;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Opengis\LaravelGeoserver\Exceptions\DatastoreNotFoundException;
use Opengis\LaravelGeoserver\Exceptions\FeatureTypeNotFoundException;
use Opengis\LaravelGeoserver\Exceptions\GeomColumnNotFoundException;
use Opengis\LaravelGeoserver\Exceptions\StyleContentNotFoundException;
use Opengis\LaravelGeoserver\Exceptions\StyleNotFoundException;
use Opengis\LaravelGeoserver\Exceptions\TableNotFoundException;
use Opengis\LaravelGeoserver\Exceptions\WorkspaceNotFoundException;

class GeoserverClient
{
    private static String $baseUri;
    private static String $username;
    private static String $password;
    private static Http $client;

    public function __construct(string $baseUri = null, string $username = null, string $password = null, Http $client = null)
    {
        is_null($client) ? self::$client = new Http : self::$client = $client;
        is_null($baseUri) && $baseUri = config('laravel-geoserver.uri');
        is_null($username) && $username = config('laravel-geoserver.username');
        is_null($password) && $password = config('laravel-geoserver.password');

        ! Str::of($baseUri)->endsWith('/') && $baseUri = $baseUri.'/';

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
            ->get(self::$baseUri.'rest/about/version')
            ->throw();
    }

    public static function getVersion(string $product = 'geoserver')
    {
        $resources = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->acceptJson()
            ->get(self::$baseUri.'rest/about/version')
            ->throw()
            ->body())->about->resource;

        foreach ($resources as $resource) {
            if (Str::of($resource->{'@name'})->lower() == Str::of($product)->lower()) {
                return $resource->Version;
            }
        }

        return 'Unknown';
    }

    public static function workspaces()
    {
        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->acceptJson()
            ->get(self::$baseUri.'rest/workspaces.json')
            ->throw());

        if (! is_null($response)) {
            if (isset($response->workspaces->workspace)) {
                return collect($response->workspaces->workspace)->map(function ($item) {
                    return self::workspace($item->name);
                });
            }
        }

        return new Collection;
    }

    public static function workspace(string $name)
    {
        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri.'rest/workspaces/'.$name.'.json')
            ->throw()
            ->body());

        if (! is_null($response)) {
            return Workspace::create($response->workspace->name, $response->workspace->isolated, true);
        }
        throw new WorkspaceNotFoundException;
    }

    public static function workspaceExists(string $name)
    {
        $body = Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri.'rest/workspaces/'.$name.'.json')
            ->body();

        return ! Str::of($body)->contains('No such workspace');
    }

    public static function deleteWorkspace(Workspace $workspace)
    {
        try {
            if (self::workspaceExists($workspace->oldName)) {
                Http::withBasicAuth(self::$username, self::$password)
                    ->delete(self::$baseUri.'rest/workspaces/'.$workspace->oldName.'?recurse=true')
                    ->throw();
            }

            return true;
        } catch (Exception $ex) {
            return false;
        }
    }

    public static function saveWorkspace(Workspace $workspace)
    {
        $data = ['workspace' => ['name' => $workspace->name, 'isolated' => $workspace->isolated]];

        if (! $workspace->isSaved) {
            if (! self::workspaceExists($workspace->oldName)) {
                Http::withBasicAuth(self::$username, self::$password)
                    ->accept('text/html')
                    ->asJson()
                    ->post(self::$baseUri.'rest/workspaces', $data)
                    ->throw();
            } else {
                Http::withBasicAuth(self::$username, self::$password)
                    ->accept('text/html')
                    ->asJson()
                    ->put(self::$baseUri.'rest/workspaces/'.$workspace->oldName, $data)
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
            ->get(self::$baseUri.'rest/workspaces/'.$workspace->oldName.'/datastores.json')
            ->throw()
            ->body());

        if (! is_null($response)) {
            if (isset($response->dataStores->dataStore)) {
                return collect($response->dataStores->dataStore)->map(function ($item) use ($workspace) {
                    return self::datastore($workspace->oldName, $item->name);
                });
            }
        }

        return new Collection;
    }

    public static function datastoreExists(string $workspaceName, string $datastoreName)
    {
        $body = Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri.'rest/workspaces/'.$workspaceName.'/datastores/'.$datastoreName.'.json')
            ->body();

        return ! Str::of($body)->contains('No such datastore');
    }

    public static function datastore(string $workspaceName, string $datastoreName)
    {
        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri.'rest/workspaces/'.$workspaceName.'/datastores/'.$datastoreName.'.json')
            ->throw()
            ->body());

        if (! is_null($response)) {
            $ds = $response->dataStore;

            if ($ds->type == 'PostGIS') {
                $entries = collect($ds->connectionParameters->entry)->mapWithKeys(function ($item) {
                    return [$item->{'@key'} => $item->{'$'}];
                })->toArray();
                $description = '';
                isset($ds->description) && $description = $ds->description;
                $datastore = PostGisDataStore::create(
                    $ds->name,
                    self::workspace($ds->workspace->name),
                    $description,
                    $entries['host'],
                    (int) $entries['port'],
                    $entries['database'],
                    $entries['schema'],
                    $entries['user'],
                    $entries['passwd'],
                    true
                );
                isset($entries['Evictor run periodicity']) && $datastore->evictorRunPeriodicity = (int) $entries['Evictor run periodicity'];
                isset($entries['Max open prepared statements']) && $datastore->maxOpenPreparedStatements = (int) $entries['Max open prepared statements'];
                isset($entries['encode functions']) && $datastore->encodeFunctions = ($entries['encode functions'] == 'true' ? true : false);
                isset($entries['Primary key metadata table']) && $datastore->primaryKeyMetadataTable = $entries['Primary key metadata table'];
                isset($entries['Batch insert size']) && $datastore->batchInsertSize = (int) $entries['Batch insert size'];
                isset($entries['preparedStatements']) && $datastore->preparedStatements = ($entries['preparedStatements'] == 'true' ? true : false);
                isset($entries['Loose bbox']) && $datastore->looseBbox = ($entries['Loose bbox'] == 'true' ? true : false);
                isset($entries['SSL mode']) && $datastore->sslMode = $entries['SSL mode'];
                isset($entries['Estimated extends']) && $datastore->estimatedExtends = ($entries['Estimated extends'] == 'true' ? true : false);
                isset($entries['fetch size']) && $datastore->fetchSize = (int) $entries['fetch size'];
                isset($entries['Expose primary keys']) && $datastore->exposePrimaryKeys = ($entries['Expose primary keys'] == 'true' ? true : false);
                isset($entries['validate connections']) && $datastore->validateConnections = ($entries['validate connections'] == 'true' ? true : false);
                isset($entries['Support on the fly geometry simplification']) && $datastore->supportOnTheFlyGeometrySimplification = ($entries['Support on the fly geometry simplification'] == 'true' ? true : false);
                isset($entries['Connection timeout']) && $datastore->connectionTimeout = (int) $entries['Connection timeout'];
                isset($entries['Callback factory']) && $datastore->callbackFactory = $entries['Callback factory'];
                isset($entries['min connections']) && $datastore->minConnections = (int) $entries['min connections'];
                isset($entries['max connections']) && $datastore->maxConnections = (int) $entries['max connections'];
                isset($entries['Evictor tests per run']) && $datastore->evictorTestsPerRun = (int) $entries['Evictor tests per run'];
                isset($entries['Test while idle']) && $datastore->testWhileIdle = ($entries['Test while idle'] == 'true' ? true : false);
                isset($entries['Max connection idle time']) && $datastore->maxConnectionIdleTime = (int) $entries['Max connection idle time'];

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
            if (! $datastore->isSaved) {
                ! self::workspaceExists($datastore->workspace->name) && $datastore->workspace = self::saveWorkspace($datastore->workspace);
                $data = [
                    'dataStore' => [
                        'name' => $datastore->name,
                        'description' => $datastore->description,
                        'type' => $datastore::TYPE,
                        'enabled' => $datastore->enabled,
                        'connectionParameters' => [
                            'entry' => [
                                ['@key' => 'schema', '$' => $datastore->schema],
                                ['@key' => 'Evictor run periodicity', '$' => $datastore->evictorRunPeriodicity],
                                ['@key' => 'Max open prepared statements', '$' => $datastore->maxOpenPreparedStatements],
                                ['@key' => 'encode functions', '$' => $datastore->encodeFunctions],
                                ['@key' => 'Primary key metadata table', '$' => $datastore->primaryKeyMetadataTable],
                                ['@key' => 'Batch insert size', '$' => $datastore->batchInsertSize],
                                ['@key' => 'preparedStatements', '$' => $datastore->preparedStatements],
                                ['@key' => 'database', '$' => $datastore->database],
                                ['@key' => 'host', '$' => $datastore->host],
                                ['@key' => 'Loose bbox', '$' => $datastore->looseBbox],
                                ['@key' => 'SSL mode', '$' => $datastore->sslMode],
                                ['@key' => 'Estimated extends', '$' => $datastore->estimatedExtends],
                                ['@key' => 'fetch size', '$' => $datastore->fetchSize],
                                ['@key' => 'Expose primary keys', '$' => $datastore->exposePrimaryKeys],
                                ['@key' => 'validate connections', '$' => $datastore->validateConnections],
                                ['@key' => 'Support on the fly geometry simplification', '$' => $datastore->supportOnTheFlyGeometrySimplification],
                                ['@key' => 'Connection timeout', '$' => $datastore->connectionTimeout],
                                ['@key' => 'Callback factory', '$' => $datastore->callbackFactory],
                                ['@key' => 'port', '$' => $datastore->port],
                                ['@key' => 'passwd', '$' => $datastore->passwd],
                                ['@key' => 'min connections', '$' => $datastore->minConnections],
                                ['@key' => 'dbtype', '$' => $datastore::DBTYPE],
                                ['@key' => 'max connections', '$' => $datastore->maxConnections],
                                ['@key' => 'Evictor tests per run', '$' => $datastore->evictorTestsPerRun],
                                ['@key' => 'Test while idle', '$' => $datastore->testWhileIdle],
                                ['@key' => 'user', '$' => $datastore->user],
                                ['@key' => 'Max connection idle time', '$' => $datastore->maxConnectionIdleTime],
                            ],
                        ],
                    ],
                ];

                if (! self::datastoreExists($datastore->workspace->oldName, $datastore->oldName)) {
                    Http::withBasicAuth(self::$username, self::$password)
                        ->accept('text/html')
                        ->asJson()
                        ->post(self::$baseUri.'rest/workspaces/'.$datastore->workspace->name.'/datastores', $data)
                        ->throw();

                    return self::datastore($datastore->workspace->name, $datastore->name);
                } else {
                    Http::withBasicAuth(self::$username, self::$password)
                        ->accept('text/html')
                        ->asJson()
                        ->put(self::$baseUri.'rest/workspaces/'.$datastore->workspace->name.'/datastores/'.$datastore->oldName, $data)
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
                    ->delete(self::$baseUri.'rest/workspaces/'.$datastore->workspace->oldName.'/datastores/'.$datastore->oldName.'?recurse=true')
                    ->throw();
            }

            return true;
        } catch (Exception $ex) {
            return false;
        }
    }

    public static function featureTypeExists(string $workspaceName, string $datastoreName, string $featureTypeName)
    {
        $body = Http::withBasicAuth(self::$username, self::$password)
            ->get(self::$baseUri.'rest/workspaces/'.$workspaceName.'/datastores/'.$datastoreName.'/featuretypes/'.$featureTypeName.'.json')
            ->body();

        return ! Str::of($body)->contains('No such feature type');
    }

    public static function featureTypes(Datastore $datastore)
    {
        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->acceptJson()
            ->get(self::$baseUri.'rest/workspaces/'.$datastore->workspace->oldName.'/datastores/'.$datastore->oldName.'/featuretypes.json')
            ->throw()
            ->body());

        if (! is_null($response)) {
            if (isset($response->featureTypes->featureType)) {
                return collect($response->featureTypes->featureType)->map(function ($item) use ($datastore) {
                    return self::featureType($datastore->workspace->name, $datastore->name, $item->name);
                });
            }
        }

        return new Collection;
    }

    public static function featureType(string $workspaceName, string $datastoreName, string $featureTypeName)
    {
        $datastore = self::datastore($workspaceName, $datastoreName);

        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->acceptJson()
            ->get(self::$baseUri.'rest/workspaces/'.$datastore->workspace->oldName.'/datastores/'.$datastore->oldName.'/featuretypes/'.$featureTypeName.'.json')
            ->throw()
            ->body());

        if (! is_null($response)) {
            if (isset($response->featureType)) {
                $ft = $response->featureType;
                $layer = PostGisLayer::create($ft->name, $ft->nativeName, $datastore);
                $layer->oldName = $ft->name;
                $layer->title = $ft->title;
                $layer->isSaved = true;

                $layerInfo = json_decode(Http::withBasicAuth(self::$username, self::$password)
                    ->acceptJson()
                    ->get(self::$baseUri.'rest/layers/'.$datastore->workspace->oldName.':'.$ft->name.'.json')
                    ->throw()
                    ->body());

                if (! is_null($layerInfo)) {
                    if (isset($layerInfo->layer)) {
                        $ws = isset($layerInfo->layer->defaultStyle->workspace) ? $layerInfo->layer->defaultStyle->workspace : null;
                        if ($ws) {
                            $name = Str::of($layerInfo->layer->defaultStyle->name)->after(':');
                        } else {
                            $name = $layerInfo->layer->defaultStyle->name;
                        }
                        $layer->defaultStyle = self::style($name, $ws);
                    }
                }


                return $layer;
            }
        }

        throw new FeatureTypeNotFoundException;
    }

    public static function saveFeatureType(PostGisLayer $layer)
    {
        if (! self::tableExists($layer->tableName, $layer->datastore->schema)) {
            throw new TableNotFoundException;
        }
        if (! self::tableHasGeom($layer->tableName, $layer->datastore->schema)) {
            throw new GeomColumnNotFoundException;
        }
        $srid = self::getSrid($layer->tableName, $layer->datastore->schema);
        $srid == 0 && $srid = 4326;

        if (! self::featureTypeExists($layer->datastore->workspace->name, $layer->datastore->name, $layer->name)) {
            $data = [
                'featureType' => [
                    'name' => $layer->name,
                    'nativeName' => $layer->tableName,
                    'title' => strlen($layer->title) > 0 ? $layer->title : $layer->name,
                    'nativeCRS' => 'EPSG:'.$srid,
                    'srs' => 'EPSG:'.$srid,
                    'nativeBoundingBox' => [
                        'minx' => -180,
                        'maxx' => 180,
                        'miny' => -90,
                        'maxy' => 90,
                        'crs' => 'EPSG:'.$srid,
                    ],
                ],
            ];

            Http::withBasicAuth(self::$username, self::$password)
                ->accept('text/html')
                ->asJson()
                ->post(self::$baseUri.'rest/workspaces/'.$layer->datastore->workspace->name.'/datastores/'.$layer->datastore->name.'/featuretypes', $data)
                ->throw();
        } else {
            $data = [
                'featureType' => [
                    'name' => $layer->name,
                    'nativeName' => $layer->tableName,
                    'title' => strlen($layer->title) > 0 ? $layer->title : $layer->name,
                    'nativeCRS' => 'EPSG:'.$srid,
                    'srs' => 'EPSG:'.$srid,
                ],
            ];
            Http::withBasicAuth(self::$username, self::$password)
                ->accept('text/html')
                ->asJson()
                ->put(self::$baseUri.'rest/workspaces/'.$layer->datastore->workspace->name.'/datastores/'.$layer->datastore->name.'/featuretypes/'.$layer->oldName, $data)
                ->throw();
        }

        return self::featureType($layer->datastore->workspace->name, $layer->datastore->name, $layer->name);
    }

    public static function deleteFeatureType(PostGisLayer $layer)
    {
        try {
            if (self::featureTypeExists($layer->datastore->workspace->oldName, $layer->datastore->oldName, $layer->oldName)) {
                Http::withBasicAuth(self::$username, self::$password)
                    ->delete(self::$baseUri.'rest/workspaces/'.$layer->datastore->workspace->oldName.'/datastores/'.$layer->datastore->oldName.'/featuretypes/'.$layer->oldName.'?recurse=true')
                    ->throw();
            }

            return true;
        } catch (Exception $ex) {
            return false;
        }
    }

    public static function saveStyle(Style $style)
    {
        // dd($style);
    }

    public static function styles(string $workspaceName = null)
    {
        if ($workspaceName) {
            if (self::workspaceExists($workspaceName)) {
                $url = self::$baseUri.'rest/workspaces/'.$workspaceName.'/styles';
                $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
                    ->acceptJson()
                    ->get($url)
                    ->throw()
                    ->body());
            } else {
                throw new WorkspaceNotFoundException;
            }
        } else {
            $url = self::$baseUri.'rest/styles';
            $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
                ->acceptJson()
                ->get($url)
                ->throw()
                ->body());
        }
        if (! is_null($response)) {
            if (isset($response->styles->style)) {
                return collect($response->styles->style)->map(function ($item) use ($workspaceName) {
                    if (! in_array($item->name, ['generic', 'line', 'point', 'polygon', 'raster'])) {
                        return self::style($item->name, $workspaceName);
                    }
                })->filter(function ($item) {
                    return ! is_null($item);
                });
            }
        }

        return new Collection;
    }

    public static function style(string $styleName, string $workspaceName = null)
    {
        if ($workspaceName) {
            if (self::workspaceExists($workspaceName)) {
                $url = self::$baseUri.'rest/workspaces/'.$workspaceName.'/styles/'.$styleName.'.json';
                try {
                    $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
                        ->acceptJson()
                        ->get($url)
                        ->throw()
                        ->body());
                } catch (Exception $ex) {
                    throw new StyleNotFoundException;
                }
            } else {
                throw new WorkspaceNotFoundException;
            }
        } else {
            $url = self::$baseUri.'rest/styles/'.$styleName.'.json';
            try {
                $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
                    ->acceptJson()
                    ->get($url)
                    ->throw()
                    ->body());
            } catch (Exception $ex) {
                throw new StyleNotFoundException;
            }
        }

        if (! is_null($response)) {
            if (isset($response->style)) {
                if ($response->style->name) {
                    $style = Style::create($response->style->name);
                }
                $workspaceName && $style->workspace = self::workspace($workspaceName);
                $styleUrl = str_replace(basename($url), $response->style->filename, $url);

                try {
                    if (! in_array($style->name, ['generic', 'line', 'point', 'polygon', 'raster'])) {
                        $style->styleContent = Http::withBasicAuth(self::$username, self::$password)
                            ->get($styleUrl)
                            ->throw()
                            ->body();
                    }

                    return $style;
                } catch (Exception $ex) {
                    throw new StyleContentNotFoundException;
                }
            }
        }
    }

    public static function layer(string $workspaceName, string $featureTypeName)
    {
        $response = json_decode(Http::withBasicAuth(self::$username, self::$password)
            ->acceptJson()
            ->get(self::$baseUri.'rest/layers/'.$workspaceName.':'.$featureTypeName.'.json')
            ->throw()
            ->body());

        // dd($response);
    }

    protected static function getSrid($tableName, $schemaName)
    {
        $field = DB::table('information_schema.columns')
            ->select('column_name', 'udt_name')
            ->whereRaw("table_name = ? and table_schema = ? and (udt_name = 'geometry' or udt_name = 'geography')")
            ->setBindings([$tableName, $schemaName])
            ->get();

        if ($field->count() > 0) {
            if ($field->first()->udt_name == 'geometry') {
                $srid = DB::table('geometry_columns')
                    ->select('srid')
                    ->whereRaw('f_table_name = ? and f_table_schema = ? and f_geometry_column = ?')
                    ->setBindings([$tableName, $schemaName, $field->first()->column_name])
                    ->get();
            } elseif ($field->first()->udt_name == 'geography') {
                $srid = DB::table('geography_columns')
                    ->select('srid')
                    ->whereRaw('f_table_name = ? and f_table_schema = ? and f_geography_column = ?')
                    ->setBindings([$tableName, $schemaName, $field->first()->column_name])
                    ->get();
            }
            if ($srid) {
                return $srid->first()->srid;
            }
        }

        return 0;
    }

    protected static function tableHasGeom(string $table, string $schema)
    {
        $field = DB::table('information_schema.columns')
            ->select('column_name', 'udt_name')
            ->whereRaw("table_name = ? and table_schema = ? and (udt_name = 'geometry' or udt_name = 'geography')")
            ->setBindings([$table, $schema])
            ->get();

        return $field->count() > 0;
    }

    protected static function tableExists(string $table, string $schema)
    {
        $table = DB::table('information_schema.columns')
            ->whereRaw('table_name = ? and table_schema = ?')
            ->setBindings([$table, $schema])
            ->get();

        return $table->count() > 0;
    }
}
