<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncLocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (version_compare(app()->version(), '8.0.0', '<')) {
            $this->markTestSkipped('Http::fake de Laravel 7 es incompatible con guzzlehttp/promises 2.x; se valida tras migrar a Laravel 13.');
        }
    }

    private function fakeMineduResponses(array $provincia, array $distrito, array $items): void
    {
        Http::fake([
            'https://escale.minedu.gob.pe/padron/rest/regiones' => Http::response([
                'region' => [
                    [
                        'idRegion' => '15',
                        'nombreRegion' => 'LIMA',
                        'pointX' => -12.046374,
                        'pointY' => -77.042793,
                        'zoom' => 10,
                    ],
                ],
            ], 200),
            'https://escale.minedu.gob.pe/padron/rest/provincias*' => Http::response([
                'provincia' => $provincia,
            ], 200),
            'https://escale.minedu.gob.pe/padron/rest/distritos*' => Http::response([
                'distrito' => $distrito,
            ], 200),
            'https://escale.minedu.gob.pe/padron/rest/instituciones*' => Http::response([
                'items' => $items,
            ], 200),
        ]);
    }

    public function test_sincroniza_respuestas_en_formato_lista(): void
    {
        $this->fakeMineduResponses(
            [[
                'idProvincia' => '1501',
                'nombreProvincia' => 'LIMA',
                'pointX' => -12.046374,
                'pointY' => -77.042793,
            ]],
            [[
                'idDistrito' => '150101',
                'nombreDistrito' => 'LIMA',
            ]],
            [[
                'codMod' => '0273572',
                'cenEdu' => 'JUAN JIMENEZ PIMENTEL',
                'cenPob' => 'LIMA',
                'dirCen' => 'JR. LAMPA 240',
                'director' => 'PEDRO PEREZ',
                'dis_crecer' => null,
                'dis_juntos' => null,
            ]]
        );

        $exitCode = Artisan::call('sync:locations');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('regions', ['idRegion' => '15', 'nombreRegion' => 'LIMA']);
        $this->assertDatabaseHas('provinces', ['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']);
        $this->assertDatabaseHas('districts', ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA']);
        $this->assertDatabaseHas('schools', [
            'codMod' => '0273572',
            'idDistrito' => '150101',
            'nombreCenEdu' => 'JUAN JIMENEZ PIMENTEL',
        ]);
    }

    public function test_sincroniza_respuestas_en_formato_objeto_unico(): void
    {
        $this->fakeMineduResponses(
            [
                'idProvincia' => '1501',
                'nombreProvincia' => 'LIMA',
                'pointX' => -12.046374,
                'pointY' => -77.042793,
            ],
            [
                'idDistrito' => '150101',
                'nombreDistrito' => 'LIMA',
            ],
            [
                'codMod' => '0273572',
                'cenEdu' => 'JUAN JIMENEZ PIMENTEL',
                'cenPob' => 'LIMA',
                'dirCen' => 'JR. LAMPA 240',
                'director' => 'PEDRO PEREZ',
                'dis_crecer' => null,
                'dis_juntos' => null,
            ]
        );

        $exitCode = Artisan::call('sync:locations');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('schools', [
            'codMod' => '0273572',
            'idDistrito' => '150101',
        ]);
    }

    public function test_pagina_instituciones_hasta_agotar_resultados(): void
    {
        $firstPage = [];

        for ($i = 1; $i <= 50; $i++) {
            $firstPage[] = [
                'codMod' => sprintf('P%06d', $i),
                'cenEdu' => "IE $i",
            ];
        }

        $secondPage = [
            ['codMod' => 'P000051', 'cenEdu' => 'IE 51'],
            ['codMod' => 'P000052', 'cenEdu' => 'IE 52'],
        ];

        Http::fake([
            'https://escale.minedu.gob.pe/padron/rest/instituciones*' => Http::sequence()
                ->push(['items' => $firstPage], 200)
                ->push(['items' => $secondPage], 200),
        ]);

        $exitCode = Artisan::call('sync:locations', ['--district' => '150101']);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('schools', 52);
        $this->assertDatabaseHas('schools', ['codMod' => 'P000052', 'idDistrito' => '150101']);
        Http::assertSentCount(2);
    }

    public function test_deduplica_codmod_repetido_entre_paginas(): void
    {
        $firstPage = [];

        for ($i = 1; $i <= 50; $i++) {
            $firstPage[] = [
                'codMod' => sprintf('P%06d', $i),
                'cenEdu' => "IE $i",
            ];
        }

        $secondPage = [
            ['codMod' => 'P000050', 'cenEdu' => 'IE 50 repetida'],
            ['codMod' => 'P000051', 'cenEdu' => 'IE 51'],
        ];

        Http::fake([
            'https://escale.minedu.gob.pe/padron/rest/instituciones*' => Http::sequence()
                ->push(['items' => $firstPage], 200)
                ->push(['items' => $secondPage], 200),
        ]);

        $exitCode = Artisan::call('sync:locations', ['--district' => '150101']);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('schools', 51);
        $this->assertDatabaseHas('schools', ['codMod' => 'P000051', 'idDistrito' => '150101']);
        Http::assertSentCount(2);
    }

    public function test_sincroniza_desde_region_especifica(): void
    {
        $this->fakeMineduResponses(
            [[
                'idProvincia' => '1501',
                'nombreProvincia' => 'LIMA',
            ]],
            [[
                'idDistrito' => '150101',
                'nombreDistrito' => 'LIMA',
            ]],
            [[
                'codMod' => '0273572',
                'cenEdu' => 'JUAN JIMENEZ PIMENTEL',
            ]]
        );

        $exitCode = Artisan::call('sync:locations', ['--region' => '15']);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('schools', ['codMod' => '0273572', 'idDistrito' => '150101']);
    }

    public function test_region_inexistente_falla(): void
    {
        $this->fakeMineduResponses([], [], []);

        $exitCode = Artisan::call('sync:locations', ['--region' => '99']);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('schools', 0);
    }
}
