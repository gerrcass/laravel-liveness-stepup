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
        <h2>Verification Info</h2>
        <ul>
            <li>External Image ID: {{ $verification['external_id'] ?? 'n/a' }}</li>
            <li>Confidence: {{ $verification['confidence'] ?? 'n/a' }}</li>
            <li>Checked at: {{ $verification['checked_at'] ?? 'n/a' }}</li>
        </ul>
        <h3>Raw match data (truncated)</h3>
        <pre style="max-height:300px;overflow:auto">{{ json_encode($verification['raw_match'] ?? [], JSON_PRETTY_PRINT) }}</pre>
    @else
        <p>No verification details available in session.</p>
    @endif

    <p><a href="{{ route('dashboard') }}">Back to dashboard</a></p>
</body>
</html>
