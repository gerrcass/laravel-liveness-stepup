<?php

namespace App\Services;

use Aws\Rekognition\RekognitionClient;
use Aws\S3\S3Client;

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
     * 
     * @param string|null $sessionName Optional session name/ID
     * @param array $options Additional options to merge
     * @param bool $useS3 Whether to use S3 for output (default: true if bucket configured)
     * @return array
     */
    public function createFaceLivenessSession(string $sessionName = null, array $options = [], bool $useS3 = true): array
    {
        $params = [
            'Settings' => [
                'AuditImagesLimit' => 4,
            ],
        ];
        
        // Only add S3 config if bucket is configured AND useS3 is true
        $s3Bucket = $useS3 ? env('AWS_S3_BUCKET') : null;
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
    public function indexFaceFromLivenessSession(string $sessionId, string $externalImageId, string $collectionId = 'users', ?array $sessionResults = null): array
    {
        // Get liveness session results (either passed in or fetched from AWS)
        if ($sessionResults === null) {
            $sessionResults = $this->getFaceLivenessSessionResults($sessionId);
        }
        
        // Get the reference image bytes (either directly from response or from S3)
        $imageBytes = $this->getReferenceImageBytes($sessionResults);

        // Ensure collection exists
        try {
            $this->client->createCollection(['CollectionId' => $collectionId]);
        } catch (\Exception $e) {
            // ignore if exists
        }

        // Index the reference image from liveness session
        $result = $this->client->indexFaces([
            'CollectionId' => $collectionId,
            'Image' => ['Bytes' => $imageBytes],
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
     * Get reference image bytes from Face Liveness session results
     * Handles both direct Bytes and S3Object storage
     */
    private function getReferenceImageBytes(array $sessionResults): string
    {
        // Check if bytes are directly available
        if (isset($sessionResults['ReferenceImage']['Bytes'])) {
            return $sessionResults['ReferenceImage']['Bytes'];
        }

        // Check if image is stored in S3
        if (isset($sessionResults['ReferenceImage']['S3Object'])) {
            $s3Object = $sessionResults['ReferenceImage']['S3Object'];
            $bucket = $s3Object['Bucket'] ?? env('AWS_S3_BUCKET');
            $key = $s3Object['Name'] ?? null;

            logger('Attempting S3 download', ['bucket' => $bucket, 'key' => $key]);

            if (!$bucket || !$key) {
                throw new \Exception('S3 object information incomplete');
            }

            // Download image from S3
            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            ]);

            try {
                $result = $s3Client->getObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]);
                $body = (string) $result->get('Body');
                logger('S3 download successful', ['size' => strlen($body)]);
                return $body;
            } catch (\Exception $e) {
                logger('S3 download failed', ['error' => $e->getMessage()]);
                throw new \Exception('Failed to download reference image from S3: ' . $e->getMessage());
            }
        }

        logger('No reference image found', ['ReferenceImage' => $sessionResults['ReferenceImage'] ?? 'not set']);
        throw new \Exception('No reference image found in liveness session results');
    }

    /**
     * Verify face using Face Liveness session
     */
    public function verifyFaceWithLiveness(string $sessionId, string $userId, string $collectionId = 'users', float $threshold = 85.0): array
    {
        // Get liveness session results
        $sessionResults = $this->getFaceLivenessSessionResults($sessionId);
        
        // Get the reference image bytes (either directly from response or from S3)
        $imageBytes = $this->getReferenceImageBytes($sessionResults);

        // Search for matching face using the reference image from liveness session
        $result = $this->client->searchFacesByImage([
            'CollectionId' => $collectionId,
            'Image' => ['Bytes' => $imageBytes],
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

    /**
     * Store an uploaded image to S3 for traditional face registration
     * 
     * @param string $imagePath Local path to the image
     * @param string $userId User ID to use in the S3 key
     * @return array S3Object information (Bucket, Name)
     */
    public function storeImageToS3(string $imagePath, string $userId): array
    {
        $bucket = env('AWS_S3_BUCKET');
        
        if (!$bucket) {
            throw new \Exception('S3 bucket not configured');
        }

        // Generate unique key with image-sessions prefix
        $extension = pathinfo($imagePath, PATHINFO_EXTENSION) ?: 'jpg';
        $key = sprintf('image-sessions/%s/%s.%s', $userId, uniqid(), $extension);

        // Get image bytes
        $imageBytes = file_get_contents($imagePath);

        // Upload to S3
        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ]);

        $result = $s3Client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $imageBytes,
            'ContentType' => mime_content_type($imagePath) ?: 'image/jpeg',
            'ACL' => 'private',
        ]);

        logger('Image uploaded to S3', ['bucket' => $bucket, 'key' => $key]);

        return [
            'Bucket' => $bucket,
            'Name' => $key,
        ];
    }
}
