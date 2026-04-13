<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GaipApiReader
{
    private const API_URL = 'https://analisi.transparenciacatalunya.cat/resource/mpbu-t5zh.json';
    private const PAGE_SIZE = 50000;

    private const ENTITY_TYPE_MAP = [
        'Ajuntament' => 'Ayuntamiento',
        'Generalitat de Catalunya' => 'Generalitat de Cataluña',
        'Entitat de dret públic' => 'Entidad de derecho público',
        'Societat vinculada a l\'Administració' => 'Sociedad vinculada a la Administración',
        'Societat vinculada a administració' => 'Sociedad vinculada a la Administración',
        'Consorci' => 'Consorcio',
        'Diputació provincial' => 'Diputación provincial',
        'Altres' => 'Otros',
        'Universitat' => 'Universidad',
        'Universitat Pública' => 'Universidad pública',
        'Consell comarcal' => 'Consejo comarcal',
        'Consell Comarcal' => 'Consejo comarcal',
        'Col·legi professional' => 'Colegio profesional',
        'Fundació' => 'Fundación',
        'Àrea Metropolitana de Barcelona' => 'Área Metropolitana de Barcelona',
        'Organisme Autònom' => 'Organismo autónomo',
        'Organisme autònom' => 'Organismo autónomo',
        'Entitat municipal descentralitzada' => 'Entidad municipal descentralizada',
        'Conselh Generau d\'Aran' => 'Consejo General de Arán',
        'Parlament de Catalunya' => 'Parlamento de Cataluña',
    ];

    private const TOPIC_MAP = [
        'Personal' => 'Personal',
        'Urbanisme' => 'Urbanismo',
        'Contractació' => 'Contratación',
        'Protecció d\'animals' => 'Protección de animales',
        'Pressupostos i comptes públics' => 'Presupuestos y cuentas públicas',
        'Control d\'activitats' => 'Control de actividades',
        'No informació pública' => 'No información pública',
        'Llicències' => 'Licencias',
        'Varis' => 'Varios',
        'Subvencions' => 'Subvenciones',
        'Estudis i informes' => 'Estudios e informes',
        'Ensenyament' => 'Enseñanza',
        'Medi ambient' => 'Medio ambiente',
        'Serveis socials' => 'Servicios sociales',
        'Altres' => 'Otros',
        'Sancions' => 'Sanciones',
        'Aigua' => 'Agua',
        'Aigüa' => 'Agua',
        'Informació institucional' => 'Información institucional',
        'Actes' => 'Actas',
        'Salut' => 'Salud',
        'Càrrecs públics' => 'Cargos públicos',
        'Registres' => 'Registros',
        'Serveis públics' => 'Servicios públicos',
        'Factures' => 'Facturas',
        'Retribucions' => 'Retribuciones',
        'Denúncies' => 'Denuncias',
        'Convenis' => 'Convenios',
        'Informació tributària' => 'Información tributaria',
        'Info tributària' => 'Información tributaria',
        'Manteniment' => 'Mantenimiento',
        'Precs i preguntes' => 'Ruegos y preguntas',
        'Investigació' => 'Investigación',
        'Tràmits' => 'Trámites',
        'Publicitat activa' => 'Publicidad activa',
        'Informació judicial' => 'Información judicial',
        'Decrets d\'alcaldia' => 'Decretos de alcaldía',
        'Arxius' => 'Archivos',
        'Inventari de béns' => 'Inventario de bienes',
        'Concessions' => 'Concesiones',
        'Certificats' => 'Certificados',
        'Obres' => 'Obras',
        'Formació' => 'Formación',
        'Festes' => 'Fiestas',
        'Actuacions administratives' => 'Actuaciones administrativas',
        'Cadastre' => 'Catastro',
        'Presons' => 'Prisiones',
        'Seguretat pública' => 'Seguridad pública',
        'Trànsit' => 'Tránsito',
        'Concessió d\'aparcament' => 'Concesión de aparcamiento',
        'Padró d\'habitants' => 'Padrón de habitantes',
        'Habitatge' => 'Vivienda',
        'Informes' => 'Informes',
        'Inspeccions' => 'Inspecciones',
        'No aplica' => 'No aplica',
        'Legislació de procediment administratiu' => 'Legislación de procedimiento administrativo',
        'Organització administrativa' => 'Organización administrativa',
        'Informació pública' => 'Información pública',
        'Sindicats' => 'Sindicatos',
        'Agents rurals' => 'Agentes rurales',
        'Educació' => 'Educación',
        'Alliberament habitatge' => 'Liberación de vivienda',
        'Avantprojectes' => 'Anteproyectos',
        'Demandes' => 'Demandas',
    ];

    /**
     * Additional outcome mappings specific to GAIP API values not covered by ExcelResolutionReader::OUTCOME_MAP.
     */
    private const EXTRA_OUTCOME_MAP = [
        'desestimació parcial' => Resolution::OUTCOME_PARTIAL,
        'derivada' => Resolution::OUTCOME_REFERRAL,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return ResolutionData[]
     */
    public function fetchAll(?int $limit = null): array
    {
        $allRecords = $this->fetchFromApi($limit);
        $results = [];

        foreach ($allRecords as $record) {
            $dto = $this->parseRecord($record);
            if ($dto !== null) {
                $results[$dto->referenceNumber] = $dto;
            }
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFromApi(?int $limit): array
    {
        $allRecords = [];
        $offset = 0;
        $pageSize = $limit !== null ? min($limit, self::PAGE_SIZE) : self::PAGE_SIZE;

        $select = implode(',', [
            'reclamaci',
            'resoluci',
            'data_de_reclamaci',
            'administraci_reclamada',
            'tipologia_d_administraci',
            'departament_de_la_generalitat',
            'mediaci',
            'ponent',
            'condici_reclamant',
            'sexe',
            'mbits',
            'tipus_de_registre',
            'sentit_de_la_resoluci',
            'data_de_resoluci',
            'informaci_reclamada',
            'paraules_clau',
            'resum',
            'url',
            'l_mits_dic_',
            'l_mit_1',
            'l_mit_2',
            'l_mit_3',
            'l_mit_4',
            'l_mit_5',
            'actes_recorreguts',
            'afectaci_a_tercers',
            'incompliment',
            'resoluci_destacada',
            'proced_ncia',
            'versi_es',
        ]);

        do {
            $url = self::API_URL . '?' . http_build_query([
                '$select' => $select,
                '$limit' => $pageSize,
                '$offset' => $offset,
                '$order' => 'data_de_resoluci DESC',
            ]);

            $this->logger->info('Fetching GAIP API', ['offset' => $offset, 'limit' => $pageSize]);

            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 120,
            ]);

            $records = $response->toArray();
            $allRecords = array_merge($allRecords, $records);

            $this->logger->info('Fetched records', ['count' => count($records), 'total' => count($allRecords)]);

            $offset += count($records);

            if ($limit !== null && count($allRecords) >= $limit) {
                $allRecords = array_slice($allRecords, 0, $limit);
                break;
            }
        } while (count($records) === $pageSize);

        return $allRecords;
    }

    private function parseRecord(array $record): ?ResolutionData
    {
        $reference = $record['reclamaci'] ?? null;
        if (empty($reference)) {
            return null;
        }

        $outcomeRaw = $record['sentit_de_la_resoluci'] ?? null;
        $outcome = $this->mapOutcome($outcomeRaw);
        if ($outcome === null) {
            $this->logger->warning('Skipping GAIP record without outcome', ['reference' => $reference]);
            return null;
        }

        // Parse dates
        $resolutionDate = $this->parseDate($record['data_de_resoluci'] ?? null);
        $claimDate = $this->parseDate($record['data_de_reclamaci'] ?? null);

        // Extract year from reference (e.g., "0001/2015" → 2015)
        $entryYear = null;
        if (preg_match('/\/(\d{4})$/', $reference, $matches)) {
            $entryYear = (int) $matches[1];
        }

        // Map entityType
        $entityTypeRaw = $record['tipologia_d_administraci'] ?? null;
        $entityType = $entityTypeRaw !== null ? (self::ENTITY_TYPE_MAP[$entityTypeRaw] ?? $entityTypeRaw) : null;

        // Map topics
        $topicRaw = $record['mbits'] ?? null;
        $topics = null;
        if ($topicRaw !== null) {
            $translated = self::TOPIC_MAP[$topicRaw] ?? $topicRaw;
            $topics = [$translated];
        }

        // Parse keywords
        $keywordsRaw = $record['paraules_clau'] ?? null;
        $keywords = null;
        if ($keywordsRaw !== null) {
            $keywords = array_map('trim', preg_split('/[;,]/', $keywordsRaw));
            $keywords = array_values(array_filter($keywords, fn (string $k) => $k !== ''));
        }

        // Parse URL
        $sourceUrl = null;
        if (isset($record['url']['url'])) {
            $sourceUrl = $record['url']['url'];
        }

        // Parse challenged acts
        $challengedActsRaw = $record['actes_recorreguts'] ?? null;
        $challengedActs = null;
        if ($challengedActsRaw !== null && $challengedActsRaw !== 'Sense acte previ') {
            $challengedActs = [$challengedActsRaw];
        }

        // Build sourceMetadata
        $sourceMetadata = array_filter([
            'resolutionNumber' => $record['resoluci'] ?? null,
            'department' => $record['departament_de_la_generalitat'] ?? null,
            'mediation' => $record['mediaci'] ?? null,
            'rapporteur' => $record['ponent'] ?? null,
            'claimantCondition' => $record['condici_reclamant'] ?? null,
            'claimantGender' => $record['sexe'] ?? null,
            'registryType' => $record['tipus_de_registre'] ?? null,
            'limitsApplied' => $record['l_mits_dic_'] ?? null,
            'limit1' => $record['l_mit_1'] ?? null,
            'limit2' => $record['l_mit_2'] ?? null,
            'limit3' => $record['l_mit_3'] ?? null,
            'limit4' => $record['l_mit_4'] ?? null,
            'limit5' => $record['l_mit_5'] ?? null,
            'thirdPartyAffected' => $record['afectaci_a_tercers'] ?? null,
            'compliance' => $record['incompliment'] ?? null,
            'featured' => $record['resoluci_destacada'] ?? null,
            'origin' => $record['proced_ncia'] ?? null,
            'documentVersion' => $record['versi_es'] ?? null,
        ], fn ($v) => $v !== null);

        // Remove "No correspon" department
        if (($sourceMetadata['department'] ?? null) === 'No correspon') {
            unset($sourceMetadata['department']);
        }

        // Remove generic limit values
        foreach (['limit1', 'limit2', 'limit3', 'limit4', 'limit5'] as $limitKey) {
            if (($sourceMetadata[$limitKey] ?? null) === 'Sense límits') {
                unset($sourceMetadata[$limitKey]);
            }
        }

        return new ResolutionData(
            referenceNumber: $reference,
            outcome: $outcome,
            source: Resolution::SOURCE_GAIP,
            scope: Resolution::SCOPE_AUTONOMOUS,
            subject: $record['informaci_reclamada'] ?? null,
            publicBodyName: $record['administraci_reclamada'] ?? null,
            claimReason: null,
            sourceUrl: $sourceUrl,
            entityType: $entityType,
            autonomousCommunityName: 'Cataluña',
            entryYear: $entryYear,
            topics: $topics,
            keywords: $keywords,
            challengedActs: $challengedActs,
            councilCriteria: null,
            sourceMetadata: !empty($sourceMetadata) ? $sourceMetadata : null,
            year: $entryYear,
            resolutionDate: $resolutionDate,
            claimDate: $claimDate,
            summary: $record['resum'] ?? null,
            complaintOrganismShortName: 'GAIP',
        );
    }

    private function mapOutcome(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($raw));

        // Check ExcelResolutionReader's map first (has Catalan outcomes)
        if (isset(ExcelResolutionReader::OUTCOME_MAP[$normalized])) {
            return ExcelResolutionReader::OUTCOME_MAP[$normalized];
        }

        // Check our extra mappings
        if (isset(self::EXTRA_OUTCOME_MAP[$normalized])) {
            return self::EXTRA_OUTCOME_MAP[$normalized];
        }

        // Partial matching for compound values
        foreach (ExcelResolutionReader::OUTCOME_MAP as $key => $value) {
            if (str_starts_with($normalized, $key)) {
                return $value;
            }
        }

        $this->logger->warning('Unmapped GAIP outcome, storing raw value', ['outcome' => $raw]);

        return $normalized;
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            $this->logger->warning('Failed to parse GAIP date', ['date' => $raw]);
            return null;
        }
    }
}
