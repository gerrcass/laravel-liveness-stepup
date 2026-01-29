<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Special Operation Result</title>
</head>
<body>
    <h1>Special Operation</h1>
    <p>User: {{ $user->name }} ({{ $user->email }})</p>

    @if($verification)
        <h2>Verificación facial (imagen enviada)</h2>
        @if(session('stepup_verification_image_path'))
            <p><strong>Imagen que verificaste:</strong></p>
            <img src="{{ route('stepup.attempt_image') }}?t={{ time() }}" alt="Imagen verificada" style="max-width:400px; max-height:400px; border:1px solid #ccc;">
        @endif
        <h3>Detalles de verificación</h3>
        <ul>
            <li>External Image ID: {{ $verification['external_id'] ?? 'n/a' }}</li>
            <li>Confidence: {{ $verification['confidence'] ?? 'n/a' }}</li>
            <li>Checked at: {{ $verification['checked_at'] ?? 'n/a' }}</li>
        </ul>
        <h3>Respuesta JSON completa de Rekognition</h3>
        <pre style="background:#f5f5f5; padding:1rem; overflow:auto; max-height:500px;">{{ json_encode($verification['rekognition_full_response'] ?? $verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    @else
        <p>No verification details available in session.</p>
    @endif

    <p><a href="{{ route('dashboard') }}">Back to dashboard</a></p>
</body>
</html>
