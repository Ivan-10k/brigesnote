<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncLocations extends Command
{
    protected $signature = 'sync:locations {--district= : Sincroniza solo un distrito por ubigeo}';

    protected $description = 'Sincroniza regiones, provincias, distritos y escuelas desde la API y las registra en la base de datos';

    private const BASE_URL = 'https://escale.minedu.gob.pe/padron/rest';

    private const BATCH_SIZE = 500;

    private const SCHOOL_PAGE_SIZE = 50;

    private array $schoolBuffer = [];

    private bool $hadFailures = false;

    public function handle(): int
    {
        DB::connection()->disableQueryLog();

        $district = $this->option('district');

        if ($district !== null) {
            $this->syncSchools($district);
            $this->flushSchools();

            if ($this->hadFailures) {
                $this->error('La sincronización finalizó con errores.');

                return self::FAILURE;
            }

            $this->info('Sincronización completa.');

            return self::SUCCESS;
        }

        $this->info('Iniciando sincronización de ubicaciones...');

        $this->syncRegions();
        $this->flushSchools();

        if ($this->hadFailures) {
            $this->error('La sincronización finalizó con errores.');

            return self::FAILURE;
        }

        $this->info('Sincronización completa.');

        return self::SUCCESS;
    }

    private function syncRegions(): void
    {
        $this->info('Sincronizando regiones...');

        $regions = $this->fetchList('regiones', ['region']);

        if ($regions === null) {
            return;
        }

        $rows = [];

        foreach ($regions as $region) {
            if (! isset($region['idRegion'])) {
                continue;
            }

            $rows[] = [
                'idRegion' => $region['idRegion'],
                'nombreRegion' => $region['nombreRegion'] ?? null,
                'pointX' => $region['pointX'] ?? null,
                'pointY' => $region['pointY'] ?? null,
                'zoom' => $region['zoom'] ?? null,
            ];
        }

        if ($rows !== []) {
            Region::upsert($rows, ['idRegion'], ['nombreRegion', 'pointX', 'pointY', 'zoom']);
        }

        foreach ($regions as $region) {
            if (isset($region['idRegion'])) {
                $this->syncProvinces($region['idRegion']);
            }
        }

        $this->info('Regiones sincronizadas correctamente.');
    }

    private function syncProvinces(string $regionId): void
    {
        $this->info("Sincronizando provincias para la región $regionId...");

        $provinces = $this->fetchList('provincias', ['provincia'], ['idRegion' => $regionId]);

        if ($provinces === null) {
            return;
        }

        $rows = [];

        foreach ($provinces as $province) {
            if (! isset($province['idProvincia'])) {
                continue;
            }

            $rows[] = [
                'idProvincia' => $province['idProvincia'],
                'nombreProvincia' => $province['nombreProvincia'] ?? null,
                'pointX' => $province['pointX'] ?? null,
                'pointY' => $province['pointY'] ?? null,
            ];
        }

        if ($rows !== []) {
            Province::upsert($rows, ['idProvincia'], ['nombreProvincia', 'pointX', 'pointY']);
        }

        foreach ($provinces as $province) {
            if (isset($province['idProvincia'])) {
                $this->syncDistricts($province['idProvincia']);
            }
        }

        $this->info("Provincias para la región $regionId sincronizadas correctamente.");
    }

    private function syncDistricts(string $provinceId): void
    {
        $this->info("Sincronizando distritos para la provincia $provinceId...");

        $districts = $this->fetchList('distritos', ['distrito'], ['idProvincia' => $provinceId]);

        if ($districts === null) {
            return;
        }

        $rows = [];

        foreach ($districts as $district) {
            if (! isset($district['idDistrito'])) {
                continue;
            }

            $rows[] = [
                'idDistrito' => $district['idDistrito'],
                'nombreDistrito' => $district['nombreDistrito'] ?? null,
            ];
        }

        if ($rows !== []) {
            District::upsert($rows, ['idDistrito'], ['nombreDistrito']);
        }

        foreach ($districts as $district) {
            if (isset($district['idDistrito'])) {
                $this->syncSchools($district['idDistrito']);
            }
        }

        $this->info("Distritos para la provincia $provinceId sincronizados correctamente.");
    }

    private function syncSchools(string $districtId): void
    {
        $this->info("Sincronizando escuelas para el distrito $districtId...");

        $start = 0;
        $seen = [];

        while (true) {
            $schools = $this->fetchList('instituciones', ['items'], [
                'estados' => 1,
                'ubigeo' => $districtId,
                'start' => $start,
            ]);

            if ($schools === null) {
                return;
            }

            if ($schools === []) {
                break;
            }

            $newItems = 0;

            foreach ($schools as $school) {
                if (! isset($school['codMod']) || isset($seen[$school['codMod']])) {
                    continue;
                }

                $seen[$school['codMod']] = true;
                $newItems++;

                $this->schoolBuffer[] = [
                    'codMod' => $school['codMod'],
                    'idDistrito' => $districtId,
                    'nombreCenEdu' => $school['cenEdu'] ?? null,
                    'cenPob' => $school['cenPob'] ?? null,
                    'dirCen' => $school['dirCen'] ?? null,
                    'director' => $school['director'] ?? null,
                    'dis_crecer' => $school['dis_crecer'] ?? null,
                    'dis_juntos' => $school['dis_juntos'] ?? null,
                ];

                if (count($this->schoolBuffer) >= self::BATCH_SIZE) {
                    $this->flushSchools();
                }
            }

            if (count($schools) < self::SCHOOL_PAGE_SIZE || $newItems === 0) {
                break;
            }

            $start += count($schools);
        }

        $this->info("Escuelas para el distrito $districtId sincronizadas correctamente.");
    }

    private function flushSchools(): void
    {
        if ($this->schoolBuffer === []) {
            return;
        }

        foreach (array_chunk($this->schoolBuffer, self::BATCH_SIZE) as $chunk) {
            School::upsert($chunk, ['codMod'], [
                'idDistrito',
                'nombreCenEdu',
                'cenPob',
                'dirCen',
                'director',
                'dis_crecer',
                'dis_juntos',
            ]);
        }

        $this->schoolBuffer = [];
    }

    private function fetchList(string $endpoint, array $keys, array $query = []): ?array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(15)
                ->timeout(60)
                ->retry(3, 1000, function ($exception) {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError());
                })
                ->get(self::BASE_URL.'/'.$endpoint, $query);
        } catch (ConnectionException|RequestException $e) {
            $this->hadFailures = true;
            $this->error("Error al obtener $endpoint: {$e->getMessage()}");

            return null;
        }

        if ($response->failed()) {
            $this->hadFailures = true;
            $this->error("Error al obtener $endpoint");

            return null;
        }

        $payload = $response->json();

        foreach ($keys as $key) {
            if (is_array($payload) && array_key_exists($key, $payload)) {
                $payload = $payload[$key];
                break;
            }
        }

        if (! is_array($payload)) {
            return [];
        }

        if ($payload !== [] && ! array_is_list($payload)) {
            return [$payload];
        }

        return $payload;
    }
}
