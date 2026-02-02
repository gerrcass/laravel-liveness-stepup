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

    /**
     * Used by the main step-up flow: search for a matching face in the collection (SearchFacesByImage).
     */
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

    /**
     * AWS Face Liveness API: Create a Face Liveness session for registration or verification
     */
    public function createFaceLivenessSession(string $sessionName = null, array $options = []): array
    {
        $params = [
            'Settings' => [
                'AuditImagesLimit' => 4,
            ],
        ];
        
        // Only add S3 config if bucket is configured
        $s3Bucket = env('AWS_S3_BUCKET');
        if ($s3Bucket) {
            $params['Settings']['OutputConfig'] = [
                'S3Bucket' => $s3Bucket,
                'S3KeyPrefix' => 'face-liveness-sessions/',
            ];
        }
        
        if ($sessionName) {
            $params['ClientRequestToken'] = $sessionName;
        }
        
        $params = array_merge($params, $options);

        $result = $this->client->createFaceLivenessSession($params);
        return $result->toArray();
    }

    /** 
     * AWS Face Liveness API: Get session results and extract reference image for face indexing
     */
    public function getFaceLivenessSessionResults(string $sessionId): array
    {
        $result = $this->client->getFaceLivenessSessionResults([
            'SessionId' => $sessionId,
        ]);

        return $result->toArray();
    }

    /**
     * Clean Face Liveness results by removing binary data for safe JSON storage
     */
    private function cleanLivenessResultForStorage(array $livenessResult): array
    {
        $cleaned = $livenessResult;
        
        // Handle ReferenceImage binary data
        if (isset($cleaned['ReferenceImage']['Bytes'])) {
            $bytesLength = strlen($cleaned['ReferenceImage']['Bytes']);
            unset($cleaned['ReferenceImage']['Bytes']);
            $cleaned['ReferenceImage']['HasBytes'] = true;
            $cleaned['ReferenceImage']['BytesLength'] = $bytesLength;
        }
        
        // Handle AuditImages binary data
        if (isset($cleaned['AuditImages']) && is_array($cleaned['AuditImages'])) {
            foreach ($cleaned['AuditImages'] as $index => $auditImage) {
                if (isset($auditImage['Bytes'])) {
                    $bytesLength = strlen($auditImage['Bytes']);
                    unset($cleaned['AuditImages'][$index]['Bytes']);
                    $cleaned['AuditImages'][$index]['HasBytes'] = true;
                    $cleaned['AuditImages'][$index]['BytesLength'] = $bytesLength;
                }
            }
        }
        
        return $cleaned;
    }

    /**
     * Index face from Face Liveness session results
     */
    public function indexFaceFromLivenessSession(string $sessionId, string $externalImageId, string $collectionId = 'users'): array
    {
        // Get liveness session results
        $sessionResults = $this->getFaceLivenessSessionResults($sessionId);
        
        if (!isset($sessionResults['ReferenceImage']['Bytes'])) {
            throw new \Exception('No reference image found in liveness session results');
        }

        // Ensure collection exists
        try {
            $this->client->createCollection(['CollectionId' => $collectionId]);
        } catch (\Exception $e) {
            // ignore if exists
        }

        // Index the reference image from liveness session
        $result = $this->client->indexFaces([
            'CollectionId' => $collectionId,
            'Image' => ['Bytes' => $sessionResults['ReferenceImage']['Bytes']],
            'ExternalImageId' => (string) $externalImageId,
            'DetectionAttributes' => [],
        ]);

        // Clean liveness result for database storage
        $livenessResultForStorage = $this->cleanLivenessResultForStorage($sessionResults);

        return [
            'indexResult' => $result->toArray(),
            'livenessResult' => $livenessResultForStorage,
        ];
    }

    /**
     * Verify face using Face Liveness session
     */
    public function verifyFaceWithLiveness(string $sessionId, string $userId, string $collectionId = 'users', float $threshold = 85.0): array
    {
        // Get liveness session results
        $sessionResults = $this->getFaceLivenessSessionResults($sessionId);
        
        if (!isset($sessionResults['ReferenceImage']['Bytes'])) {
            throw new \Exception('No reference image found in liveness session results');
        }

        // Search for matching face using the reference image from liveness session
        $result = $this->client->searchFacesByImage([
            'CollectionId' => $collectionId,
            'Image' => ['Bytes' => $sessionResults['ReferenceImage']['Bytes']],
            'FaceMatchThreshold' => $threshold,
            'MaxFaces' => 1,
        ]);

        // Clean liveness result for storage
        $livenessResultForStorage = $this->cleanLivenessResultForStorage($sessionResults);

        return [
            'searchResult' => $result->toArray(),
            'livenessResult' => $livenessResultForStorage,
            'livenessConfidence' => $sessionResults['Confidence'] ?? 0,
        ];
    }
}
