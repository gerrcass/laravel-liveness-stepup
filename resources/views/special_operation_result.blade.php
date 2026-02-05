@extends('layouts.app')

@section('title', 'Operación Protegida')

@section('nav_back')
    <a href="{{ route('dashboard') }}">← Volver al dashboard</a>
@endsection

@section('content')
    <h1>✅ Operación Protegida</h1>
    <p><strong>Estás viendo esto porque superaste la verificación facial exitosamente.</strong> Solo los usuarios con rol privilegiado y que hayan pasado la "step-up authentication" pueden acceder aquí.</p>

    @if($verification)
        @php
            $method = $verification['method'] ?? 'image';
            $confidence = $verification['confidence'] ?? $verification['face_confidence'] ?? 0;
            $livenessConfidence = $verification['liveness_confidence'] ?? null;
            $faceId = $verification['face_id'] ?? null;
            $externalId = $verification['external_id'] ?? null;
            $userId = Auth::id();
        @endphp

        {{-- Success area with green styling --}}
        <div style="color:#155724; background-color:#d4edda; border:1px solid #c3e6cb; padding:1rem; border-radius:4px; margin-bottom:1rem;">
            <h3 style="margin-top:0; color:#155724;">✅ Verification Successful</h3>
            <p style="margin-bottom:1rem;">
                <strong>Your identity has been verified successfully</strong>
                @if($method === 'liveness')
                    via Face Liveness
                @else
                    via Image-based verification
                @endif
            </p>

            {{-- Technical details --}}
            <div style="background:rgba(0,0,0,0.05); padding:0.75rem; border-radius:4px;">
                <h4 style="margin:0 0 0.5rem 0; color:#155724;">Verification Details</h4>
                @if($livenessConfidence !== null)
                    <p style="margin:0.25rem 0;">
                        <strong>Liveness Confidence:</strong>
                        <span style="color: {{ $livenessConfidence >= 60 ? '#28a745' : '#dc3545' }};">
                            {{ number_format($livenessConfidence, 1) }}%
                        </span>
                    </p>
                @endif
                @if($confidence)
                    <p style="margin:0.25rem 0;">
                        <strong>Face Match Confidence:</strong>
                        <span style="color: {{ $confidence >= 60 ? '#28a745' : '#dc3545' }};">
                            {{ number_format($confidence, 1) }}% (passed)
                        </span>
                    </p>
                @endif
                @if($faceId)
                    <p style="margin:0.25rem 0;"><strong>Face ID:</strong> {{ $faceId }}</p>
                @endif
                <p style="margin:0.25rem 0;">
                    <strong>User ID:</strong>
                    {{ $userId }} (this is you)
                </p>
                @if(isset($verification['checked_at']))
                    <p style="margin:0.25rem 0;"><strong>Verified at:</strong> {{ $verification['checked_at'] }}</p>
                @endif
            </div>

            {{-- Raw API responses from each AWS Rekognition call --}}
            @if($method === 'liveness' && isset($verification['liveness_result']))
                <details style="margin-top:1rem;">
                    <summary style="cursor:pointer; color:#155724; font-weight:bold;">GetFaceLivenessSessionResults (Face Liveness API)</summary>
                    <pre style="background:#fff; padding:0.75rem; overflow:auto; max-height:300px; margin-top:0.5rem; border:1px solid #c3e6cb; font-size:0.85rem;">{{ json_encode($verification['liveness_result'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            @endif

            @if(isset($verification['rekognition_response']))
                <details style="margin-top:0.5rem;">
                    <summary style="cursor:pointer; color:#155724; font-weight:bold;">SearchFacesByImage (Face Recognition API)</summary>
                    <pre style="background:#fff; padding:0.75rem; overflow:auto; max-height:300px; margin-top:0.5rem; border:1px solid #c3e6cb; font-size:0.85rem;">{{ json_encode($verification['rekognition_response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            @endif

            {{-- Image used in verification --}}
            @if(session('stepup_verification_image_path'))
                <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid #c3e6cb;">
                    <p style="margin:0 0 0.5rem 0; font-weight:bold;">Image used for verification:</p>
                    <img src="{{ route('stepup.attempt_image') }}?t={{ time() }}" alt="Verification image" style="max-width:300px; max-height:300px; border:1px solid #c3e6cb; border-radius:4px;">
                </div>
            @elseif($method === 'liveness')
                <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid #c3e6cb;">
                    <p style="margin:0 0 0.5rem 0; font-weight:bold;">Reference image from Face Liveness verification:</p>
                    <img src="{{ route('stepup.liveness_verification_image') }}?t={{ time() }}" alt="Face Liveness verification image" style="max-width:300px; max-height:300px; border:1px solid #c3e6cb; border-radius:4px;">
                </div>
            @endif
        </div>
    @else
        <p>No hay detalles de verificación en sesión.</p>
    @endif

    <p><a href="{{ route('dashboard') }}">Volver al dashboard</a></p>
@endsection
