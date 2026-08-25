<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FindCepSettingsRepository;
use RuntimeException;

/**
 * Cliente HTTP para a API FindCEP (OpenAPI 1.8.3).
 * Docs: https://www.findcep.com/docs/index.html
 */
class FindCepService
{
    public function __construct(private FindCepSettingsRepository $settingsRepository)
    {
    }

    public function getSettings(): ?array
    {
        return $this->settingsRepository->getSettings();
    }

    public function saveSettings(array $data): void
    {
        $this->settingsRepository->saveSettings($data);
    }

    public function resolveBaseUrl(?array $settings = null): string
    {
        $settings = $settings ?? $this->getSettings() ?? [];
        $custom = rtrim(trim((string) ($settings['custom_base_url'] ?? '')), '/');
        if ($custom !== '') {
            return $custom;
        }

        $scheme = strtolower(trim((string) ($settings['scheme'] ?? 'https')));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $clientId = trim((string) ($settings['client_id'] ?? ''));
        if ($clientId === '') {
            $clientId = 'demo';
        }

        $hash = trim((string) ($settings['client_url_hash'] ?? ''));
        $host = $hash !== ''
            ? $clientId . '-' . $hash . '.api.findcep.com'
            : $clientId . '.api.findcep.com';

        return $scheme . '://' . $host;
    }

    public function resolveReferer(?array $settings = null): string
    {
        $settings = $settings ?? $this->getSettings() ?? [];
        $referer = trim((string) ($settings['referer'] ?? ''));
        if ($referer !== '') {
            return $referer;
        }

        $fid = trim((string) ($settings['fid'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        if ($fid !== '' && $clientId !== '') {
            return $fid . '.' . $clientId;
        }
        if ($fid !== '') {
            return $fid;
        }
        if ($clientId !== '') {
            return $clientId;
        }

        return 'https://www.findcep.com';
    }

    /**
     * Catálogo das rotas documentadas no OpenAPI FindCEP.
     *
     * @return list<array{id:string,group:string,method:string,path:string,summary:string,fields:list<array{name:string,label:string,type?:string,required?:bool,placeholder?:string,hint?:string}>}>
     */
    public function catalog(): array
    {
        return [
            [
                'id' => 'cep',
                'group' => 'API CEP',
                'method' => 'GET',
                'path' => '/v1/cep/{cep}.json',
                'summary' => 'Busca endereço por CEP',
                'fields' => [
                    ['name' => 'cep', 'label' => 'CEP', 'required' => true, 'placeholder' => '01234000'],
                ],
            ],
            [
                'id' => 'cep_removido',
                'group' => 'API CEP',
                'method' => 'GET',
                'path' => '/v1/cep/removido/{cep}.json',
                'summary' => 'CEP removido da base oficial (desde 2015)',
                'fields' => [
                    ['name' => 'cep', 'label' => 'CEP removido', 'required' => true, 'placeholder' => '11680000'],
                ],
            ],
            [
                'id' => 'endereco_pesquisa',
                'group' => 'API Endereço',
                'method' => 'POST',
                'path' => '/v1/endereco/pesquisa',
                'summary' => 'Busca CEP por endereço (template livre)',
                'fields' => [
                    ['name' => 'query_string', 'label' => 'Termo', 'required' => true, 'placeholder' => 'avenida pacaembu'],
                    ['name' => 'from', 'label' => 'From', 'type' => 'number', 'placeholder' => '0'],
                    ['name' => 'size', 'label' => 'Size', 'type' => 'number', 'placeholder' => '10'],
                ],
            ],
            [
                'id' => 'endereco_template_ibge',
                'group' => 'API Endereço',
                'method' => 'POST',
                'path' => '/v1/endereco/template/codigo_ibge',
                'summary' => 'Busca endereço por código IBGE',
                'fields' => [
                    ['name' => 'query_string', 'label' => 'Logradouro', 'required' => true],
                    ['name' => 'codigo_ibge', 'label' => 'Código IBGE', 'required' => true, 'placeholder' => '3550308'],
                    ['name' => 'from', 'label' => 'From', 'type' => 'number', 'placeholder' => '0'],
                    ['name' => 'size', 'label' => 'Size', 'type' => 'number', 'placeholder' => '10'],
                ],
            ],
            [
                'id' => 'endereco_template_uf',
                'group' => 'API Endereço',
                'method' => 'POST',
                'path' => '/v1/endereco/template/uf',
                'summary' => 'Busca endereço filtrando por UF',
                'fields' => [
                    ['name' => 'query_string', 'label' => 'Logradouro', 'required' => true],
                    ['name' => 'uf', 'label' => 'UF', 'required' => true, 'placeholder' => 'sp'],
                    ['name' => 'from', 'label' => 'From', 'type' => 'number', 'placeholder' => '0'],
                    ['name' => 'size', 'label' => 'Size', 'type' => 'number', 'placeholder' => '10'],
                ],
            ],
            [
                'id' => 'endereco_template_uf_cidade',
                'group' => 'API Endereço',
                'method' => 'POST',
                'path' => '/v1/endereco/template/uf/cidade',
                'summary' => 'Busca endereço filtrando por UF + cidade',
                'fields' => [
                    ['name' => 'query_string', 'label' => 'Logradouro', 'required' => true],
                    ['name' => 'uf', 'label' => 'UF', 'required' => true, 'placeholder' => 'sp'],
                    ['name' => 'cidade', 'label' => 'Cidade', 'required' => true, 'placeholder' => 'sao paulo'],
                    ['name' => 'from', 'label' => 'From', 'type' => 'number', 'placeholder' => '0'],
                    ['name' => 'size', 'label' => 'Size', 'type' => 'number', 'placeholder' => '10'],
                ],
            ],
            [
                'id' => 'endereco_v2_pesquisa',
                'group' => 'API Endereço V2',
                'method' => 'POST',
                'path' => '/v2/endereco/pesquisa',
                'summary' => 'V2 — busca CEP por endereço (template livre)',
                'fields' => [
                    ['name' => 'query_string', 'label' => 'Termo', 'required' => true],
                    ['name' => 'from', 'label' => 'From', 'type' => 'number', 'placeholder' => '0'],
                    ['name' => 'size', 'label' => 'Size', 'type' => 'number', 'placeholder' => '10'],
                ],
            ],
            [
                'id' => 'endereco_v2_template_ibge',
                'group' => 'API Endereço V2',
                'method' => 'POST',
                'path' => '/v2/endereco/template/codigo_ibge',
                'summary' => 'V2 — busca por código IBGE',
                'fields' => [
                    ['name' => 'query_string', 'label' => 'Logradouro', 'required' => true],
                    ['name' => 'codigo_ibge', 'label' => 'Código IBGE', 'required' => true],
                    ['name' => 'from', 'label' => 'From', 'type' => 'number', 'placeholder' => '0'],
                    ['name' => 'size', 'label' => 'Size', 'type' => 'number', 'placeholder' => '10'],
                ],
            ],
            [
                'id' => 'endereco_v2_template_uf',
                'group' => 'API Endereço V2',
                'method' => 'POST',
                'path' => '/v2/endereco/template/uf',
                'summary' => 'V2 — busca por UF',
                'fields' => [
                    ['name' => 'query_string', 'label' => 'Logradouro', 'required' => true],
                    ['name' => 'uf', 'label' => 'UF', 'required' => true],
                    ['name' => 'from', 'label' => 'From', 'type' => 'number', 'placeholder' => '0'],
                    ['name' => 'size', 'label' => 'Size', 'type' => 'number', 'placeholder' => '10'],
                ],
            ],
            [
                'id' => 'endereco_v2_template_uf_cidade',
                'group' => 'API Endereço V2',
                'method' => 'POST',
                'path' => '/v2/endereco/template/uf/cidade',
                'summary' => 'V2 — busca por UF + cidade',
                'fields' => [
                    ['name' => 'query_string', 'label' => 'Logradouro', 'required' => true],
                    ['name' => 'uf', 'label' => 'UF', 'required' => true],
                    ['name' => 'cidade', 'label' => 'Cidade', 'required' => true],
                    ['name' => 'from', 'label' => 'From', 'type' => 'number', 'placeholder' => '0'],
                    ['name' => 'size', 'label' => 'Size', 'type' => 'number', 'placeholder' => '10'],
                ],
            ],
            [
                'id' => 'geo_cep',
                'group' => 'API Geolocalização',
                'method' => 'GET',
                'path' => '/v1/geolocation/cep/{cep}',
                'summary' => 'Latitude/longitude por CEP',
                'fields' => [
                    ['name' => 'cep', 'label' => 'CEP', 'required' => true, 'placeholder' => '01234000'],
                ],
            ],
            [
                'id' => 'geo_dist_cep_cep',
                'group' => 'API Geo Distance',
                'method' => 'GET',
                'path' => '/v1/geolocation/distance/from/cep/{cep_from}/to/cep/{cep_to}',
                'summary' => 'Distância CEP → CEP',
                'fields' => [
                    ['name' => 'cep_from', 'label' => 'CEP origem', 'required' => true],
                    ['name' => 'cep_to', 'label' => 'CEP destino', 'required' => true],
                ],
            ],
            [
                'id' => 'geo_dist_cep_latlon',
                'group' => 'API Geo Distance',
                'method' => 'GET',
                'path' => '/v1/geolocation/distance/from/cep/{cep_from}/to/latlon/{latlon_to}',
                'summary' => 'Distância CEP → lat,lon',
                'fields' => [
                    ['name' => 'cep_from', 'label' => 'CEP origem', 'required' => true],
                    ['name' => 'latlon_to', 'label' => 'Lat,Lon destino', 'required' => true, 'placeholder' => '-23.56,-46.64'],
                ],
            ],
            [
                'id' => 'geo_dist_latlon_latlon',
                'group' => 'API Geo Distance',
                'method' => 'GET',
                'path' => '/v1/geolocation/distance/from/latlon/{latlon_from}/to/latlon/{latlon_to}',
                'summary' => 'Distância lat,lon → lat,lon',
                'fields' => [
                    ['name' => 'latlon_from', 'label' => 'Lat,Lon origem', 'required' => true],
                    ['name' => 'latlon_to', 'label' => 'Lat,Lon destino', 'required' => true],
                ],
            ],
            [
                'id' => 'geo_dist_cep_cep_approx',
                'group' => 'API Geo Distance',
                'method' => 'GET',
                'path' => '/v1/geolocation/distance/from/cep/{cep_from}/to/cep/{cep_to}/approximate/{approximate}',
                'summary' => 'Distância CEP → CEP (aproximada)',
                'fields' => [
                    ['name' => 'cep_from', 'label' => 'CEP origem', 'required' => true],
                    ['name' => 'cep_to', 'label' => 'CEP destino', 'required' => true],
                    ['name' => 'approximate', 'label' => 'Approximate', 'required' => true, 'placeholder' => 'true'],
                ],
            ],
            [
                'id' => 'geo_dist_cep_latlon_approx',
                'group' => 'API Geo Distance',
                'method' => 'GET',
                'path' => '/v1/geolocation/distance/from/cep/{cep_from}/to/latlon/{latlon_to}/approximate/{approximate}',
                'summary' => 'Distância CEP → lat,lon (aproximada)',
                'fields' => [
                    ['name' => 'cep_from', 'label' => 'CEP origem', 'required' => true],
                    ['name' => 'latlon_to', 'label' => 'Lat,Lon destino', 'required' => true],
                    ['name' => 'approximate', 'label' => 'Approximate', 'required' => true, 'placeholder' => 'true'],
                ],
            ],
            [
                'id' => 'geo_dist_latlon_latlon_approx',
                'group' => 'API Geo Distance',
                'method' => 'GET',
                'path' => '/v1/geolocation/distance/from/latlon/{latlon_from}/to/latlon/{latlon_to}/approximate/{approximate}',
                'summary' => 'Distância lat,lon → lat,lon (aproximada)',
                'fields' => [
                    ['name' => 'latlon_from', 'label' => 'Lat,Lon origem', 'required' => true],
                    ['name' => 'latlon_to', 'label' => 'Lat,Lon destino', 'required' => true],
                    ['name' => 'approximate', 'label' => 'Approximate', 'required' => true, 'placeholder' => 'true'],
                ],
            ],
            [
                'id' => 'loc_estados',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/estados',
                'summary' => 'Lista estados',
                'fields' => [],
            ],
            [
                'id' => 'loc_cidades',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/estado/{uf}/cidades',
                'summary' => 'Cidades por UF',
                'fields' => [
                    ['name' => 'uf', 'label' => 'UF', 'required' => true, 'placeholder' => 'sp'],
                ],
            ],
            [
                'id' => 'loc_bairros_uf_cidade',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/estado/{uf}/cidade/{cidade_key}/bairros',
                'summary' => 'Bairros por UF + cidade',
                'fields' => [
                    ['name' => 'uf', 'label' => 'UF', 'required' => true],
                    ['name' => 'cidade', 'label' => 'Cidade', 'required' => true, 'hint' => 'Nome da cidade (será convertido para url_key)'],
                ],
            ],
            [
                'id' => 'loc_bairros_hash',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/cidade/{cidade_hash}/bairros',
                'summary' => 'Bairros por hash da cidade',
                'fields' => [
                    ['name' => 'cidade_hash', 'label' => 'Hash cidade', 'required' => true, 'placeholder' => 'ce474f7c7f'],
                ],
            ],
            [
                'id' => 'loc_ibge_uf_cidade',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/estado/{uf}/cidade/{cidade_key}/ibge',
                'summary' => 'IBGE por UF + cidade',
                'fields' => [
                    ['name' => 'uf', 'label' => 'UF', 'required' => true],
                    ['name' => 'cidade', 'label' => 'Cidade', 'required' => true],
                ],
            ],
            [
                'id' => 'loc_ibge_hash',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/cidade/{cidade_hash}/ibge',
                'summary' => 'IBGE por hash da cidade',
                'fields' => [
                    ['name' => 'cidade_hash', 'label' => 'Hash cidade', 'required' => true],
                ],
            ],
            [
                'id' => 'loc_municipio_ibge',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/codigo/{codigo_ibge}/municipio',
                'summary' => 'Município por código IBGE',
                'fields' => [
                    ['name' => 'codigo_ibge', 'label' => 'Código IBGE', 'required' => true],
                ],
            ],
            [
                'id' => 'loc_distritos_ibge',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/codigo/{codigo_ibge}/distritos',
                'summary' => 'Distritos por código IBGE',
                'fields' => [
                    ['name' => 'codigo_ibge', 'label' => 'Código IBGE', 'required' => true],
                ],
            ],
            [
                'id' => 'loc_paises',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/paises',
                'summary' => 'Lista países',
                'fields' => [],
            ],
            [
                'id' => 'loc_distritos',
                'group' => 'API Localidades',
                'method' => 'GET',
                'path' => '/v1/localidades/distritos',
                'summary' => 'Lista distritos',
                'fields' => [],
            ],
            [
                'id' => 'faixa_csv_sap',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/csv/customized/sap',
                'summary' => 'Export CSV customizado SAP',
                'fields' => [],
            ],
            [
                'id' => 'faixa_csv_default',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/csv/default',
                'summary' => 'Export CSV default',
                'fields' => [],
            ],
            [
                'id' => 'faixa_csv_geo',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/csv/geolocation',
                'summary' => 'Export CSV geolocation',
                'fields' => [],
            ],
            [
                'id' => 'faixa_csv_radius',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/csv/radius/from/lat/{lat}/lon/{lon}',
                'summary' => 'Export CSV raio (homologação)',
                'fields' => [
                    ['name' => 'lat', 'label' => 'Latitude', 'required' => true, 'placeholder' => '-23.56'],
                    ['name' => 'lon', 'label' => 'Longitude', 'required' => true, 'placeholder' => '-46.64'],
                ],
            ],
            [
                'id' => 'faixa_csv_route',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/csv/route/from/lat/{lat}/lon/{lon}',
                'summary' => 'Export CSV rota (homologação)',
                'fields' => [
                    ['name' => 'lat', 'label' => 'Latitude', 'required' => true],
                    ['name' => 'lon', 'label' => 'Longitude', 'required' => true],
                ],
            ],
            [
                'id' => 'faixa_csv_toll',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/csv/toll_route/from/lat/{lat}/lon/{lon}',
                'summary' => 'Export CSV pedágio (homologação)',
                'fields' => [
                    ['name' => 'lat', 'label' => 'Latitude', 'required' => true],
                    ['name' => 'lon', 'label' => 'Longitude', 'required' => true],
                ],
            ],
            [
                'id' => 'faixa_json_sap',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/json/customized/sap',
                'summary' => 'Export JSON customizado SAP',
                'fields' => [],
            ],
            [
                'id' => 'faixa_json_default',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/json/default',
                'summary' => 'Export JSON default',
                'fields' => [],
            ],
            [
                'id' => 'faixa_json_geo',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/json/geolocation',
                'summary' => 'Export JSON geolocation',
                'fields' => [],
            ],
            [
                'id' => 'faixa_json_radius',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/json/radius/from/lat/{lat}/lon/{lon}',
                'summary' => 'Export JSON raio',
                'fields' => [
                    ['name' => 'lat', 'label' => 'Latitude', 'required' => true],
                    ['name' => 'lon', 'label' => 'Longitude', 'required' => true],
                    ['name' => 'filter_states_code', 'label' => 'Filtro UFs', 'placeholder' => 'sp-mg'],
                    ['name' => 'filter_radius', 'label' => 'Raio (km)', 'type' => 'number', 'placeholder' => '100'],
                ],
            ],
            [
                'id' => 'faixa_json_route',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/json/route/from/lat/{lat}/lon/{lon}',
                'summary' => 'Export JSON rota',
                'fields' => [
                    ['name' => 'lat', 'label' => 'Latitude', 'required' => true],
                    ['name' => 'lon', 'label' => 'Longitude', 'required' => true],
                ],
            ],
            [
                'id' => 'faixa_json_toll',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/export/json/toll_route/from/lat/{lat}/lon/{lon}',
                'summary' => 'Export JSON pedágio',
                'fields' => [
                    ['name' => 'lat', 'label' => 'Latitude', 'required' => true],
                    ['name' => 'lon', 'label' => 'Longitude', 'required' => true],
                ],
            ],
            [
                'id' => 'faixa_search_ibge',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/search/ibge_code/{ibge_code}',
                'summary' => 'Faixa de CEP por código IBGE',
                'fields' => [
                    ['name' => 'ibge_code', 'label' => 'Código IBGE', 'required' => true],
                ],
            ],
            [
                'id' => 'faixa_search_postal',
                'group' => 'API Faixa de CEP',
                'method' => 'GET',
                'path' => '/v1/faixadecep/search/postal_code/{postal_code}',
                'summary' => 'Faixa de CEP por CEP',
                'fields' => [
                    ['name' => 'postal_code', 'label' => 'CEP', 'required' => true, 'placeholder' => '01234000'],
                ],
            ],
            [
                'id' => 'routes_latlon',
                'group' => 'API Routes',
                'method' => 'GET',
                'path' => '/v1/routes/from/latlon/{origin}/to/latlon/{destination}',
                'summary' => 'Rota entre dois pontos (exige Authorization)',
                'fields' => [
                    ['name' => 'origin', 'label' => 'Origem lat,lon', 'required' => true, 'placeholder' => '-20.94849,-48.48187'],
                    ['name' => 'destination', 'label' => 'Destino lat,lon', 'required' => true, 'placeholder' => '-21.40879,-48.50516'],
                ],
            ],
            [
                'id' => 'routes_latlon_model',
                'group' => 'API Routes',
                'method' => 'GET',
                'path' => '/v1/routes/from/latlon/{origin}/to/latlon/{destination}/by/{model}',
                'summary' => 'Rota com modelo (exige Authorization)',
                'fields' => [
                    ['name' => 'origin', 'label' => 'Origem lat,lon', 'required' => true],
                    ['name' => 'destination', 'label' => 'Destino lat,lon', 'required' => true],
                    ['name' => 'model', 'label' => 'Modelo', 'required' => true, 'placeholder' => 'car'],
                ],
            ],
            [
                'id' => 'consumo',
                'group' => 'API Consumo',
                'method' => 'GET',
                'path' => '/v1/consumo/cliente/{client_id}/fid/{fid}',
                'summary' => 'Relatório de uso (mês atual + 3 anteriores)',
                'fields' => [
                    ['name' => 'client_id', 'label' => 'Client ID', 'hint' => 'Vazio = usa config salva'],
                    ['name' => 'fid', 'label' => 'FID', 'hint' => 'Vazio = usa config salva'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array{success:bool,http_code:int,url:string,method:string,operation:string,referer:string,body:mixed,raw:string,error:?string}
     */
    public function execute(string $operationId, array $params = []): array
    {
        $op = null;
        foreach ($this->catalog() as $item) {
            if ($item['id'] === $operationId) {
                $op = $item;
                break;
            }
        }
        if ($op === null) {
            throw new RuntimeException('Operação FindCEP desconhecida: ' . $operationId);
        }

        $settings = $this->getSettings() ?? [];
        $prepared = $this->prepareParams($operationId, $params, $settings);
        $path = $this->buildPath($op['path'], $prepared['path']);
        $query = $prepared['query'];
        $jsonBody = $prepared['body'];

        return $this->request(
            $op['method'],
            $path,
            $jsonBody,
            $query,
            $settings,
            $operationId
        );
    }

    public function testConnection(): array
    {
        return $this->execute('cep', ['cep' => '01001000']);
    }

    /**
     * @param array<string, scalar|null> $params
     * @param array<string, mixed> $settings
     * @return array{path:array<string,string>,query:array<string,string>,body:?array}
     */
    private function prepareParams(string $operationId, array $params, array $settings): array
    {
        $path = [];
        $query = [];
        $body = null;

        $normalizeCep = static function ($value): string {
            return preg_replace('/\D+/', '', (string) $value) ?? '';
        };

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '') {
                continue;
            }
            $params[$key] = $value;
        }

        switch ($operationId) {
            case 'cep':
            case 'cep_removido':
            case 'geo_cep':
                $path['cep'] = $normalizeCep($params['cep'] ?? '');
                break;

            case 'endereco_pesquisa':
            case 'endereco_v2_pesquisa':
                $body = [
                    'id' => 'pesquisa_endereco',
                    'params' => [
                        'from' => (int) ($params['from'] ?? 0),
                        'size' => (int) ($params['size'] ?? 10),
                        'query_string' => (string) ($params['query_string'] ?? ''),
                    ],
                ];
                break;

            case 'endereco_template_ibge':
            case 'endereco_v2_template_ibge':
                $body = [
                    'id' => 'pesquisa_endereco_por_codigo_ibge',
                    'params' => [
                        'from' => (int) ($params['from'] ?? 0),
                        'size' => (int) ($params['size'] ?? 10),
                        'query_string' => (string) ($params['query_string'] ?? ''),
                        'codigo_ibge' => (string) ($params['codigo_ibge'] ?? ''),
                    ],
                ];
                break;

            case 'endereco_template_uf':
            case 'endereco_v2_template_uf':
                $body = [
                    'id' => 'pesquisa_endereco_por_uf',
                    'params' => [
                        'from' => (int) ($params['from'] ?? 0),
                        'size' => (int) ($params['size'] ?? 10),
                        'query_string' => (string) ($params['query_string'] ?? ''),
                        'uf' => strtolower((string) ($params['uf'] ?? '')),
                    ],
                ];
                break;

            case 'endereco_template_uf_cidade':
            case 'endereco_v2_template_uf_cidade':
                $body = [
                    'id' => 'pesquisa_endereco_por_uf_cidade',
                    'params' => [
                        'from' => (int) ($params['from'] ?? 0),
                        'size' => (int) ($params['size'] ?? 10),
                        'query_string' => (string) ($params['query_string'] ?? ''),
                        'uf' => strtolower((string) ($params['uf'] ?? '')),
                        'cidade' => (string) ($params['cidade'] ?? ''),
                    ],
                ];
                break;

            case 'geo_dist_cep_cep':
            case 'geo_dist_cep_cep_approx':
                $path['cep_from'] = $normalizeCep($params['cep_from'] ?? '');
                $path['cep_to'] = $normalizeCep($params['cep_to'] ?? '');
                if (isset($params['approximate'])) {
                    $path['approximate'] = (string) $params['approximate'];
                }
                break;

            case 'geo_dist_cep_latlon':
            case 'geo_dist_cep_latlon_approx':
                $path['cep_from'] = $normalizeCep($params['cep_from'] ?? '');
                $path['latlon_to'] = (string) ($params['latlon_to'] ?? '');
                if (isset($params['approximate'])) {
                    $path['approximate'] = (string) $params['approximate'];
                }
                break;

            case 'geo_dist_latlon_latlon':
            case 'geo_dist_latlon_latlon_approx':
                $path['latlon_from'] = (string) ($params['latlon_from'] ?? '');
                $path['latlon_to'] = (string) ($params['latlon_to'] ?? '');
                if (isset($params['approximate'])) {
                    $path['approximate'] = (string) $params['approximate'];
                }
                break;

            case 'loc_cidades':
                $path['uf'] = strtolower((string) ($params['uf'] ?? ''));
                break;

            case 'loc_bairros_uf_cidade':
            case 'loc_ibge_uf_cidade':
                $path['uf'] = strtolower((string) ($params['uf'] ?? ''));
                $path['cidade_key'] = $this->cityUrlKey((string) ($params['cidade'] ?? ''));
                break;

            case 'loc_bairros_hash':
            case 'loc_ibge_hash':
                $path['cidade_hash'] = (string) ($params['cidade_hash'] ?? '');
                break;

            case 'loc_municipio_ibge':
            case 'loc_distritos_ibge':
                $path['codigo_ibge'] = (string) ($params['codigo_ibge'] ?? '');
                break;

            case 'faixa_csv_radius':
            case 'faixa_csv_route':
            case 'faixa_csv_toll':
            case 'faixa_json_route':
            case 'faixa_json_toll':
                $path['lat'] = (string) ($params['lat'] ?? '');
                $path['lon'] = (string) ($params['lon'] ?? '');
                break;

            case 'faixa_json_radius':
                $path['lat'] = (string) ($params['lat'] ?? '');
                $path['lon'] = (string) ($params['lon'] ?? '');
                if (!empty($params['filter_states_code'])) {
                    $query['filter_states_code'] = (string) $params['filter_states_code'];
                }
                if (isset($params['filter_radius']) && $params['filter_radius'] !== '') {
                    $query['filter_radius'] = (string) (int) $params['filter_radius'];
                }
                break;

            case 'faixa_search_ibge':
                $path['ibge_code'] = (string) ($params['ibge_code'] ?? '');
                break;

            case 'faixa_search_postal':
                $path['postal_code'] = $normalizeCep($params['postal_code'] ?? '');
                break;

            case 'routes_latlon':
                $path['origin'] = (string) ($params['origin'] ?? '');
                $path['destination'] = (string) ($params['destination'] ?? '');
                break;

            case 'routes_latlon_model':
                $path['origin'] = (string) ($params['origin'] ?? '');
                $path['destination'] = (string) ($params['destination'] ?? '');
                $path['model'] = (string) ($params['model'] ?? '');
                break;

            case 'consumo':
                $path['client_id'] = trim((string) ($params['client_id'] ?? '')) !== ''
                    ? (string) $params['client_id']
                    : (string) ($settings['client_id'] ?? '');
                $path['fid'] = trim((string) ($params['fid'] ?? '')) !== ''
                    ? (string) $params['fid']
                    : (string) ($settings['fid'] ?? '');
                break;
        }

        return ['path' => $path, 'query' => $query, 'body' => $body];
    }

    /**
     * @param array<string, string> $vars
     */
    private function buildPath(string $template, array $vars): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function (array $m) use ($vars): string {
            $key = $m[1];
            if (!array_key_exists($key, $vars) || $vars[$key] === '') {
                throw new RuntimeException('Parâmetro obrigatório ausente: ' . $key);
            }

            return rawurlencode($vars[$key]);
        }, $template) ?? $template;
    }

    public function cityUrlKey(string $city): string
    {
        $city = trim($city);
        if ($city === '') {
            throw new RuntimeException('Informe a cidade.');
        }
        $key = mb_strtolower($city, 'UTF-8');

        return preg_replace('/\s+/u', '-', $key) ?? $key;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, mixed> $settings
     * @return array{success:bool,http_code:int,url:string,method:string,operation:string,referer:string,body:mixed,raw:string,error:?string}
     */
    public function request(
        string $method,
        string $path,
        ?array $jsonBody = null,
        array $query = [],
        ?array $settings = null,
        string $operationId = ''
    ): array {
        $settings = $settings ?? $this->getSettings() ?? [];
        $base = $this->resolveBaseUrl($settings);
        $referer = $this->resolveReferer($settings);
        $timeout = max(5, (int) ($settings['timeout_seconds'] ?? 30));
        $authorization = trim((string) ($settings['authorization'] ?? ''));

        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Referer: ' . $referer,
            'User-Agent: PortalWCT-FindCEP/1.0',
        ];
        if ($authorization !== '') {
            $headers[] = 'Authorization: ' . $authorization;
        }

        $payload = null;
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Falha ao montar JSON da requisição FindCEP.');
            }
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensão cURL do PHP não está disponível.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_REFERER => $referer,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'url' => $url,
                'method' => strtoupper($method),
                'operation' => $operationId,
                'referer' => $referer,
                'body' => null,
                'raw' => '',
                'error' => $error ?: 'Falha na requisição HTTP.',
            ];
        }

        $raw = (string) $raw;
        $decoded = json_decode($raw, true);
        $body = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'url' => $url,
            'method' => strtoupper($method),
            'operation' => $operationId,
            'referer' => $referer,
            'body' => $body,
            'raw' => $raw,
            'error' => $httpCode >= 200 && $httpCode < 300 ? null : ('HTTP ' . $httpCode),
        ];
    }
}
