<?php

namespace App\Services;

use Aws\Rekognition\RekognitionClient;

class RekognitionService
{
    private RekognitionClient $client;

    public function __construct()
    {
        $this->client = new RekognitionClient([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            // credentials will be resolved by the SDK from env/profile
        ]);
    }

    public function indexFace(string $imagePath, string $externalImageId, string $collectionId = 'users') : array
    {
        // Ensure collection exists
        try {
            $this->client->createCollection(['CollectionId' => $collectionId]);
        } catch (\Exception $e) {
            // ignore if exists
        }

        $bytes = file_get_contents($imagePath);

        $result = $this->client->indexFaces([
            'CollectionId' => $collectionId,
            'Image' => ['Bytes' => $bytes],
            'ExternalImageId' => (string) $externalImageId,
            'DetectionAttributes' => [],
        ]);

        return $result->toArray();
    }

    public function searchFace(string $imagePath, string $collectionId = 'users', float $threshold = 85.0)
    {
        $bytes = file_get_contents($imagePath);

        $result = $this->client->searchFacesByImage([
            'CollectionId' => $collectionId,
            'Image' => ['Bytes' => $bytes],
            'FaceMatchThreshold' => $threshold,
            'MaxFaces' => 1,
        ]);

        return $result->toArray();
    }

    public function createFaceLivenessSession(string $sessionName = null, array $options = []): array
    {
        $params = [];
        if ($sessionName) {
            $params['SessionName'] = $sessionName;
        }
        $params = array_merge($params, $options);

        $result = $this->client->createFaceLivenessSession($params);
        return $result->toArray();
    }

    public function getFaceLivenessSessionResults(string $sessionId): array
    {
        $result = $this->client->getFaceLivenessSessionResults([
            'SessionId' => $sessionId,
        ]);

        return $result->toArray();
    }
}
