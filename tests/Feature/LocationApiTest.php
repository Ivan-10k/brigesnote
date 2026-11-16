<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Region::create([
            'idRegion' => '15',
            'nombreRegion' => 'LIMA',
            'pointX' => -12.046374,
            'pointY' => -77.042793,
            'zoom' => 10,
        ]);

        Region::create([
            'idRegion' => '16',
            'nombreRegion' => 'CALLAO',
            'pointX' => -12.056590,
            'pointY' => -77.118140,
            'zoom' => 11,
        ]);

        Province::create([
            'idProvincia' => '1501',
            'nombreProvincia' => 'LIMA',
            'pointX' => -12.046374,
            'pointY' => -77.042793,
        ]);

        Province::create([
            'idProvincia' => '1601',
            'nombreProvincia' => 'CALLAO',
            'pointX' => -12.056590,
            'pointY' => -77.118140,
        ]);

        District::create([
            'idDistrito' => '150101',
            'nombreDistrito' => 'LIMA',
        ]);

        District::create([
            'idDistrito' => '160101',
            'nombreDistrito' => 'CALLAO',
        ]);

        School::create([
            'codMod' => '0273572',
            'idDistrito' => '150101',
            'nombreCenEdu' => 'JUAN JIMENEZ PIMENTEL',
            'cenPob' => 'LIMA',
            'dirCen' => 'JR. LAMPA 240',
            'director' => 'PEDRO PEREZ',
            'dis_crecer' => null,
            'dis_juntos' => null,
        ]);
    }

    public function test_regiones_devuelve_todas_las_regiones(): void
    {
        $response = $this->getJson('/api/regiones');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.idRegion', '15')
            ->assertJsonPath('data.1.idRegion', '16');
    }

    public function test_provincias_filtra_por_prefijo_de_region(): void
    {
        $response = $this->getJson('/api/provincias/15');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.idProvincia', '1501');
    }

    public function test_distritos_filtra_por_prefijo_de_provincia(): void
    {
        $response = $this->getJson('/api/distritos/1501');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.idDistrito', '150101');
    }

    public function test_colegios_filtra_por_distrito(): void
    {
        $response = $this->getJson('/api/colegios/150101');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codMod', '0273572')
            ->assertJsonPath('data.0.idDistrito', '150101');
    }

    public function test_provincias_devuelve_404_si_no_hay_coincidencias(): void
    {
        $response = $this->getJson('/api/provincias/99');

        $response->assertNotFound()
            ->assertJsonPath('status', 'error');
    }

    public function test_distritos_devuelve_404_si_no_hay_coincidencias(): void
    {
        $response = $this->getJson('/api/distritos/9999');

        $response->assertNotFound()
            ->assertJsonPath('status', 'error');
    }

    public function test_colegios_devuelve_404_si_no_hay_coincidencias(): void
    {
        $response = $this->getJson('/api/colegios/999999');

        $response->assertNotFound()
            ->assertJsonPath('status', 'error');
    }
}
