@extends('layouts.app')

@section('title', 'Step-Up Verification')

@section('nav_back')
    <a href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <h1>Step-Up Verification</h1>
    @if($errors->any())
        <div style="color:#721c24; background-color:#f8d7da; border:1px solid #f5c6cb; padding:1rem; border-radius:4px; margin-bottom:1rem;">
            {{ $errors->first() }}
        </div>
    @endif

    @if(session('status'))
        <div style="color:#155724; background-color:#d4edda; border:1px solid #c3e6cb; padding:1rem; border-radius:4px; margin-bottom:1rem;">
            {{ session('status') }}
        </div>
    @endif

    @if($registrationMethod === 'liveness')
        <div style="margin-bottom: 20px; padding: 15px; background-color: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px;">
            <h3 style="margin-top:0; color:#004085;">Face Liveness Verification</h3>
            <p>You registered using Face Liveness. Please complete the Face Liveness check to verify your identity.</p>
        </div>

        <div id="liveness-verification-container" style="min-height: 400px; display: flex; align-items: center; justify-content: center;">
            <div id="face-liveness-root"></div>
        </div>

        <form id="liveness-verification-form" action="{{ route('stepup.verify') }}" method="POST" style="display: none;">
            @csrf
            <input type="hidden" name="liveness_session_id" id="liveness_session_id">
        </form>
    @else
        <div style="margin-bottom: 20px; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
            <h3 style="margin-top:0; color:#856404;">Image-based Verification</h3>
            <p>You registered using a face image. Please upload a live image for verification.</p>
        </div>

        <form action="{{ route('stepup.verify') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <label for="live_image" style="display:block; margin-bottom:0.5rem; font-weight:bold;">Capture/Upload Live Image:</label>
            <input type="file" name="live_image" accept="image/*" required style="margin-bottom:1rem;">
            <br>
            <button type="submit" style="padding:10px 20px; font-size:16px; background-color:#007bff; color:white; border:none; border-radius:4px; cursor:pointer;">
                Verify Identity
            </button>
        </form>
    @endif

    @if(session('stepup_last_attempt_image_path') || session('stepup_last_attempt_rekognition_response'))
        <hr style="margin: 2rem 0;">
        <h3>Last Verification Attempt Details</h3>
        @if(session('stepup_last_attempt_rekognition_response'))
            @php
                $rekognitionResponse = session('stepup_last_attempt_rekognition_response');
                $livenessConf = $rekognitionResponse['LivenessConfidence'] ?? null;
                $faceConf = $rekognitionResponse['FaceMatches'][0]['Similarity'] ?? null;
            @endphp

            @if($livenessConf || $faceConf)
                <div style="background:#f8f9fa; padding:1rem; border-radius:4px; margin-bottom:1rem;">
                    <h4 style="margin-top:0;">Confidence Scores</h4>
                    @if($livenessConf)
                        <p style="color: {{ $livenessConf >= 60 ? '#28a745' : '#dc3545' }};">
                            <strong>Liveness Confidence:</strong> {{ number_format($livenessConf, 1) }}% (minimum 60% required)
                        </p>
                    @endif
                    @if($faceConf)
                        <p style="color: {{ $faceConf >= 60 ? '#28a745' : '#dc3545' }};">
                            <strong>Face Match Confidence:</strong> {{ number_format($faceConf, 1) }}% (minimum 60% required)
                        </p>
                    @endif
                </div>
            @endif
        @endif

        <p><strong>Image submitted:</strong></p>
        @if(session('stepup_last_attempt_image_path'))
            <img src="{{ route('stepup.attempt_image') }}?t={{ time() }}" alt="Verification attempt image" style="max-width:400px; max-height:400px; border:1px solid #ccc; border-radius:4px;">
        @endif
        @if(session('stepup_last_attempt_rekognition_response'))
            <details style="margin-top:1rem;">
                <summary style="cursor:pointer; color:#007bff;">Show raw Rekognition response</summary>
                <pre style="background:#f5f5f5; padding:1rem; overflow:auto; max-height:400px; margin-top:0.5rem;">{{ json_encode(session('stepup_last_attempt_rekognition_response'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endif
    @endif

    @if($registrationMethod === 'liveness')
        <script>
            let livenessVerificationCompleted = false;

            window.onLivenessComplete = function(result) {
                if (result.success && result.verified) {
                    livenessVerificationCompleted = true;
                    window.location.href = '{{ session("stepup_intended.url", route("dashboard")) }}';
                } else {
                }
            };

            window.onLivenessError = function(error) {
                console.error('Face Liveness error:', error);
            };

            document.addEventListener('DOMContentLoaded', function() {
                if (window.initializeFaceLiveness) {
                    window.initializeFaceLiveness('verification');
                }
            });
        </script>
    @endif
@endsection
