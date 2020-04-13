<?php

namespace Opengis\LaravelGeoserver\Tests;

use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Collection;
use Opengis\LaravelGeoserver\Workspace;
use Opengis\LaravelGeoserver\PostGisLayer;
use Opengis\LaravelGeoserver\GeoserverClient;
use Opengis\LaravelGeoserver\PostGisDataStore;
use Opengis\LaravelGeoserver\LaravelGeoserverServiceProvider;

class LaravelGeoserverTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        GeoserverClient::create();
    }

    protected function getPackageProviders($app)
    {
        return [LaravelGeoserverServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        include_once __DIR__ . '/../database/migrations/create_locations_table.php';

        (new \CreateLocationsTable)->up();
    }

    /** @test */
    public function client_instanciate_and_connects()
    {
        $this->assertInstanceOf(GeoserverClient::class, GeoserverClient::create());
    }

    /** @test */
    public function client_returns_a_product_version()
    {
        $version = GeoserverClient::getVersion('geoserver');

        $this->assertTrue(strlen($version) > 0);
    }

    /** @test */
    public function workspace_and_datastores_can_be_persisted_updated_and_deleted_on_server()
    {
        $workspaceName = Str::random(32);
        $workspace = Workspace::create($workspaceName)->save();

        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertEquals($workspaceName, $workspace->name);

        $workspaces = GeoserverClient::workspaces();
        $this->assertInstanceOf(Collection::class, $workspaces);

        $workspace = GeoserverClient::workspace($workspaceName);
        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertEquals($workspaceName, $workspace->name);

        $olsWorkspaceName = $workspace->name;
        $newWorkspaceName = Str::random(32);
        $workspace->name = $newWorkspaceName;

        $workspace = $workspace->save();
        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertEquals($newWorkspaceName, $workspace->name);
        $this->assertFalse(GeoserverClient::workspaceExists($olsWorkspaceName));

        $workspace->isolated = true;
        $workspace = $workspace->save();
        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertEquals($newWorkspaceName, $workspace->name);
        $this->assertTrue($workspace->isolated);

        $datastoreName = Str::random(16);
        $datastoreDescription = Str::random(32);
        $datastoreHost = 'postgis'; // as specified in docker-compose.yml service name
    $datastorePort = 5432; // as specified in docker-compose.yml service port
    $datastoreDatabase = env('DB_DATABASE');
        $datastoreSchema = env('DB_SCHEMA');
        $datastoreUser = env('DB_USERNAME');
        $datastorePassword = env('DB_PASSWORD');

        $datastore = PostGisDataStore::create($datastoreName, $workspace, $datastoreDescription, $datastoreHost, $datastorePort, $datastoreDatabase, $datastoreSchema, $datastoreUser, $datastorePassword)->save();
        $this->assertInstanceOf(PostGisDataStore::class, $datastore);
        $this->assertEquals($datastoreName, $datastore->name);

        $datastores = GeoserverClient::datastores($workspace);
        $this->assertInstanceOf(Collection::class, $datastores);
        $this->assertCount(1, $datastores);

        $this->assertInstanceOf(Collection::class, $workspace->datastores());
        $this->assertCount(1, $workspace->datastores());

        $layerName = Str::random(16);

        $layer = PostGisLayer::create($layerName, 'locations', $datastore)->save();
        $this->assertInstanceOf(PostGisLayer::class, $layer);
        $this->assertEquals($layerName, $layer->name);

        $layers = GeoserverClient::featureTypes($datastore);
        $this->assertInstanceOf(Collection::class, $layers);
        $this->assertCount(1, $layers);

        $this->assertTrue($layer->delete());
        $this->assertFalse(GeoserverClient::featureTypeExists($workspace->name, $datastore->name, $layer->name));

        $this->assertTrue($datastore->delete());
        $this->assertFalse(GeoserverClient::datastoreExists($workspace->name, $datastore->name));

        $this->assertTrue($workspace->delete());
        $this->assertFalse(GeoserverClient::workspaceExists($workspace->name));
    }
}
