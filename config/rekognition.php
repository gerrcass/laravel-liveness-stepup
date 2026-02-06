<?php

return [
    'collection_name' => env('REKOGNITION_COLLECTION_NAME', 'users'),
    'confidence_threshold' => (float) env('REKOGNITION_CONFIDENCE_THRESHOLD', 85.0),
];
