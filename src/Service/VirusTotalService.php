<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class VirusTotalService
{
    private const API_URL_UPLOAD = 'https://www.virustotal.com/api/v3/files';
    private const API_URL_ANALYSE = 'https://www.virustotal.com/api/v3/analyses/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey
    ) {}

    // ══════════════════════════════════════════════════════════
    //  Envoyer un fichier à VirusTotal et attendre le rapport
    // ══════════════════════════════════════════════════════════
    public function scanFile(string $filePath): array
    {
        // ── Étape 1 : Upload du fichier ──
        $uploadResult = $this->uploadFile($filePath);

        if (isset($uploadResult['error'])) {
            return $uploadResult;
        }

        $analysisId = $uploadResult['data']['id'] ?? null;

        if (!$analysisId) {
            return ['error' => 'Impossible de récupérer l\'ID d\'analyse VirusTotal.'];
        }

        // ── Étape 2 : Attendre et récupérer le rapport ──
        return $this->getReport($analysisId);
    }

    // ══════════════════════════════════════════════════════════
    //  Upload du fichier vers VirusTotal
    // ══════════════════════════════════════════════════════════
    private function uploadFile(string $filePath): array
    {
        try {
            $fileSize = filesize($filePath);

            // Fichiers > 32 Mo : utiliser l'URL d'upload spéciale
            if ($fileSize > 32 * 1024 * 1024) {
                return ['error' => 'Fichier trop volumineux pour VirusTotal (max 32 Mo).'];
            }

            $response = $this->httpClient->request('POST', self::API_URL_UPLOAD, [
                'headers' => [
                    'x-apikey' => $this->apiKey,
                ],
                'body' => [
                    'file' => fopen($filePath, 'r'),
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                return [
                    'error' => 'Erreur lors de l\'envoi du fichier à VirusTotal.',
                    'code'  => $statusCode,
                ];
            }

            return $response->toArray();

        } catch (\Exception $e) {
            return ['error' => 'Connexion à VirusTotal impossible : ' . $e->getMessage()];
        }
    }

    // ══════════════════════════════════════════════════════════
    //  Récupérer le rapport d'analyse
    //  VirusTotal peut prendre quelques secondes à analyser
    // ══════════════════════════════════════════════════════════
    private function getReport(string $analysisId): array
    {
        $maxAttempts = 10;  // maximum 10 tentatives
        $waitSeconds = 5;   // attendre 5 secondes entre chaque tentative

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->httpClient->request(
                    'GET',
                    self::API_URL_ANALYSE . $analysisId,
                    [
                        'headers' => [
                            'x-apikey' => $this->apiKey,
                        ],
                    ]
                );

                $data   = $response->toArray();
                $status = $data['data']['attributes']['status'] ?? 'unknown';

                // Analyse terminée
                if ($status === 'completed') {
                    return $this->formatReport($data);
                }

                // Analyse encore en cours → attendre
                if ($attempt < $maxAttempts) {
                    sleep($waitSeconds);
                }

            } catch (\Exception $e) {
                return ['error' => 'Erreur lors de la récupération du rapport : ' . $e->getMessage()];
            }
        }

        return [
            'error'  => 'L\'analyse VirusTotal n\'a pas pu être complétée dans le délai imparti.',
            'statut' => 'timeout',
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  Formater le rapport pour l'API
    // ══════════════════════════════════════════════════════════
    private function formatReport(array $data): array
    {
        $stats = $data['data']['attributes']['stats'] ?? [];

        $malicious  = $stats['malicious']  ?? 0;
        $suspicious = $stats['suspicious'] ?? 0;
        $harmless   = $stats['harmless']   ?? 0;
        $undetected = $stats['undetected'] ?? 0;
        $total      = $malicious + $suspicious + $harmless + $undetected;

        // Résultat global
        $isSafe = ($malicious === 0 && $suspicious === 0);

        // Détail des moteurs qui ont détecté une menace
        $threats = [];
        $results = $data['data']['attributes']['results'] ?? [];

        foreach ($results as $engine => $result) {
            if (in_array($result['category'], ['malicious', 'suspicious'])) {
                $threats[] = [
                    'moteur'   => $engine,
                    'categorie'=> $result['category'],
                    'menace'   => $result['result'] ?? 'inconnu',
                ];
            }
        }

        return [
            'statut'     => $isSafe ? 'safe' : 'danger',
            'isSafe'     => $isSafe,
            'resume'     => [
                'malicieux'  => $malicious,
                'suspect'    => $suspicious,
                'propre'     => $harmless,
                'non_detecte'=> $undetected,
                'total'      => $total,
            ],
            'menaces'    => $threats,
            'message'    => $isSafe
                ? 'Aucune menace détectée. Le document peut être validé.'
                : "Menace détectée par $malicious moteur(s). Validation déconseillée.",
        ];
    }
}