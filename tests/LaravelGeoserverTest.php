<?php

namespace Opengis\LaravelGeoserver\Tests;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Opengis\LaravelGeoserver\GeoserverClient;
use Opengis\LaravelGeoserver\LaravelGeoserverServiceProvider;
use Opengis\LaravelGeoserver\PostGisDataStore;
use Opengis\LaravelGeoserver\Workspace;
use Orchestra\Testbench\TestCase;

class LaravelGeoserverTest extends TestCase
{
    // public function setUp(): void
    // {
    //     parent::setUp();
    //     // Http::fake();
    // }

    protected function getPackageProviders($app)
    {
        return [LaravelGeoserverServiceProvider::class];
    }

    /** @test */
    public function client_instanciate_and_connects()
    {
        Http::fake();
        $this->assertInstanceOf(GeoserverClient::class, GeoserverClient::create());
    }

    /** @test */
    public function client_returns_a_product_version()
    {
        Http::fake(function ($request) {
            return Http::response('{
                "about": {
                    "resource": [
                        {
                            "@name": "GeoServer",
                            "Build-Timestamp": "20-Jan-2020 18:21",
                            "Version": "2.16.2",
                            "Git-Revision": "d68399795ec4a5cff98e2a2c6cc472ec1628e368"
                        },
                        {
                            "@name": "GeoTools",
                            "Build-Timestamp": "20-Jan-2020 17:45",
                            "Version": 22.2,
                            "Git-Revision": "648dda215d0ec25671eabc119de4d2be80a992fd"
                        },
                        {
                            "@name": "GeoWebCache",
                            "Version": "1.16.2",
                            "Git-Revision": "1.16.x/84a6736412921b9e6a587f0f150822eb3bf238bc"
                        }
                    ]
                }
            }', 200);
        });

        $version = GeoserverClient::getVersion('geoserver');

        $this->assertTrue(strlen($version) > 0);
    }

    // /** @test */
    public function client_returns_a_collection_of_workspaces()
    {
        Http::fake(function ($request) {
            return Http::response('{
                "workspaces": {
                    "workspace": [
                            {
                                "name": "workspace1",
                                "href": "http://localhost:8080/geoserver/rest/workspaces/workspace1.json"
                            },
                            {
                                "name": "workspace2",
                                "href": "http://localhost:8080/geoserver/rest/workspaces/workspace2.json"
                            }
                        ]
                    }
                }', 200);
        });

        $workspaces = GeoserverClient::workspaces();
        $this->assertInstanceOf(Collection::class, $workspaces);
        $this->assertCount(2, $workspaces);
    }

    /** @test */
    public function client_returns_a_single_workspace()
    {
        Http::fake(function ($request) {
            return Http::response('{
                    "workspace": {
                        "name": "workspace1",
                        "isolated": false,
                        "dataStores": "http://localhost:8080/geoserver/rest/workspaces/workspace1/datastores.json",
                        "coverageStores": "http://localhost:8080/geoserver/rest/workspaces/workspace1/coveragestores.json",
                        "wmsStores": "http://localhost:8080/geoserver/rest/workspaces/workspace1/wmsstores.json",
                        "wmtsStores": "http://localhost:8080/geoserver/rest/workspaces/workspace1/wmtsstores.json"
                    }
                }', 200);
        });

        $workspace = GeoserverClient::workspace('workspace1');
        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertEquals("workspace1", $workspace->name);
    }

    /** @test */
    // public function workspace_and_datastores_can_be_persisted_updated_and_deleted_on_server()
    // {
    //     $workspaceName = Str::random(32);
    //     $workspace = Workspace::create($workspaceName)->save();

    //     $this->assertInstanceOf(Workspace::class, $workspace);
    //     $this->assertEquals($workspaceName, $workspace->name);

    //     $workspaces = GeoserverClient::workspaces();
    //     $this->assertInstanceOf(Collection::class, $workspaces);

    //     $workspace = GeoserverClient::workspace($workspaceName);
    //     $this->assertInstanceOf(Workspace::class, $workspace);
    //     $this->assertEquals($workspaceName, $workspace->name);

    //     $olsWorkspaceName = $workspace->name;
    //     $newWorkspaceName = Str::random(32);
    //     $workspace->name = $newWorkspaceName;

    //     $workspace = $workspace->save();
    //     $this->assertInstanceOf(Workspace::class, $workspace);
    //     $this->assertEquals($newWorkspaceName, $workspace->name);
    //     $this->assertFalse(GeoserverClient::workspaceExists($olsWorkspaceName));

    //     $workspace->isolated = true;
    //     $workspace = $workspace->save();
    //     $this->assertInstanceOf(Workspace::class, $workspace);
    //     $this->assertEquals($newWorkspaceName, $workspace->name);
    //     $this->assertTrue($workspace->isolated);

    //     $datastoreName = Str::random(32);
    //     $datastoreDescription = Str::random(32);
    //     $datastoreHost = Str::random(16);
    //     $datastoreDatabase = Str::random(16);
    //     $datastoreSchema = Str::random(8);
    //     $datastoreUser = Str::random(8);
    //     $datastorePassword = Str::random(16);

    //     $datastore = PostGisDataStore::create($datastoreName, $workspace, $datastoreDescription, $datastoreHost, 5432, $datastoreDatabase, $datastoreSchema, $datastoreUser, $datastorePassword)->save();
    //     $this->assertInstanceOf(PostGisDataStore::class, $datastore);
    //     $this->assertEquals($datastoreName, $datastore->name);

    //     $datastores = GeoserverClient::datastores($workspace);
    //     $this->assertInstanceOf(Collection::class, $datastores);
    //     $this->assertCount(1, $datastores);

    //     $this->assertInstanceOf(Collection::class, $workspace->datastores());
    //     $this->assertCount(1, $workspace->datastores());

    //     $this->assertTrue($datastore->delete());
    //     $this->assertFalse(GeoserverClient::datastoreExists($workspace->name, $datastore->name));

    //     $this->assertTrue($workspace->delete());
    //     $this->assertFalse(GeoserverClient::workspaceExists($workspace->name));
    // }
}
