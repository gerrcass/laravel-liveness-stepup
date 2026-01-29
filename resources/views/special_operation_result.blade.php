@extends('layouts.app')

@section('title', 'Operación Protegida')

@section('nav_back')
    <a href="{{ route('dashboard') }}">← Volver al dashboard</a>
@endsection

@section('content')
    <h1>✅ Operación Protegida</h1>
    <p><strong>Estás viendo esto porque superaste la verificación facial exitosamente.</strong> Solo los usuarios con rol privilegiado y que hayan pasado la "step-up authentication" pueden acceder aquí.</p>

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
        <p>No hay detalles de verificación en sesión.</p>
    @endif

    <p><a href="{{ route('dashboard') }}">Volver al dashboard</a></p>
@endsection
