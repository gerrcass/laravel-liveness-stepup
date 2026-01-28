<?php

namespace App\Services;

use Aws\Sts\StsClient;

class StsService
{
    private StsClient $client;

    public function __construct()
    {
        $this->client = new StsClient([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ]);
    }

    /**
     * Return temporary credentials for client-side calls (short lived)
     */
    public function getSessionToken(int $durationSeconds = 3600): array
    {
        $result = $this->client->getSessionToken([
            'DurationSeconds' => $durationSeconds,
        ]);

        return $result->toArray();
    }
}
