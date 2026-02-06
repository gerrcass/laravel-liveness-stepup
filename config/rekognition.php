<?php

return [
    'collection_name' => env('REKOGNITION_COLLECTION_NAME', 'users'),
    'confidence_threshold' => env('REKOGNITION_CONFIDENCE_THRESHOLD', 60.0),
];
