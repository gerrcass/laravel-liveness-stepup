@extends('layouts.app')

@section('title', 'Step-Up Verification')

@section('nav_back')
    <a href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <h1>Step-Up Verification</h1>

    {{-- Error area with detailed feedback --}}
    @if($errors->any() || session('stepup_error_details'))
        @php
            $errorMessage = $errors->first() ?? session('stepup_error_message') ?? 'Verification failed';
            $errorDetails = session('stepup_error_details') ?? null;
            $errorImagePath = session('stepup_error_image_path') ?? null;
        @endphp
        <div style="color:#721c24; background-color:#f8d7da; border:1px solid #f5c6cb; padding:1rem; border-radius:4px; margin-bottom:1rem;">
            <h3 style="margin-top:0; color:#721c24;">Face Verification Failed</h3>
            <p style="margin-bottom:1rem;"><strong>Reason:</strong> {{ $errorMessage }}</p>

            {{-- Error details if available --}}
            @if($errorDetails)
                @php
                    $livenessConf = $errorDetails['LivenessConfidence'] ?? null;
                    $faceConf = $errorDetails['FaceMatches'][0]['Similarity'] ?? null;
                    $faceId = $errorDetails['FaceMatches'][0]['Face']['FaceId'] ?? null;
                    $externalId = $errorDetails['FaceMatches'][0]['Face']['ExternalImageId'] ?? null;
                    $userId = Auth::id();
                @endphp

                @if($livenessConf || $faceConf || $faceId)
                    <div style="background:rgba(0,0,0,0.05); padding:0.75rem; border-radius:4px; margin-bottom:1rem;">
                        <h4 style="margin:0 0 0.5rem 0; color:#721c24;">Technical Details</h4>
                        @if($livenessConf)
                            <p style="margin:0.25rem 0;">
                                <strong>Liveness Confidence:</strong>
                                <span style="color: {{ $livenessConf >= 60 ? '#28a745' : '#dc3545' }};">
                                    {{ number_format($livenessConf, 1) }}% {{ $livenessConf >= 60 ? '(passed)' : '(below 60% threshold)' }}
                                </span>
                            </p>
                        @endif
                        @if($faceConf)
                            <p style="margin:0.25rem 0;">
                                <strong>Face Match Confidence:</strong>
                                <span style="color: {{ $faceConf >= 60 ? '#28a745' : '#dc3545' }};">
                                    {{ number_format($faceConf, 1) }}%
                                    @if($externalId == (string)$userId)
                                        (passed)
                                    @else
                                        (matched different user - {{ $externalId }})
                                    @endif
                                </span>
                            </p>
                        @endif
                        @if($faceId)
                            <p style="margin:0.25rem 0;"><strong>Face ID:</strong> {{ $faceId }}</p>
                        @endif
                        @if($externalId)
                            <p style="margin:0.25rem 0;">
                                <strong>Matched User ID:</strong>
                                {{ $externalId }}
                                @if($externalId == (string)$userId)
                                    <span style="color:#28a745;">(this is you)</span>
                                @else
                                    <span style="color:#dc3545;">(different user)</span>
                                @endif
                            </p>
                        @endif
                    </div>
                @endif

                {{-- Accordion with raw API response --}}
                <details style="margin-top:1rem;">
                    <summary style="cursor:pointer; color:#721c24; font-weight:bold;">Show raw Rekognition API response</summary>
                    <pre style="background:#fff; padding:0.75rem; overflow:auto; max-height:300px; margin-top:0.5rem; border:1px solid #f5c6cb; font-size:0.85rem;">{{ json_encode($errorDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            @endif

            {{-- Image used in verification attempt --}}
            @if($errorImagePath)
                <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid #f5c6cb;">
                    <p style="margin:0 0 0.5rem 0; font-weight:bold;">Image used for verification:</p>
                    <img src="{{ route('stepup.error_image') }}?t={{ time() }}" alt="Verification attempt image" style="max-width:300px; max-height:300px; border:1px solid #f5c6cb; border-radius:4px;">
                </div>
            @endif
        </div>
    @endif

    {{-- Success message --}}
    @if(session('status'))
        <div style="color:#155724; background-color:#d4edda; border:1px solid #c3e6cb; padding:1rem; border-radius:4px; margin-bottom:1rem;">
            {{ session('status') }}
        </div>
    @endif

    {{-- Face Liveness verification UI --}}
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

    {{-- Image-based verification UI --}}
    @else
        <div style="margin-bottom: 20px; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
            <h3 style="margin-top:0; color:#856404;">Image-based Verification</h3>
            <p>You registered using a face image. Please upload a live image for verification.</p>
        </div>

        <form id="image-verification-form" action="{{ route('stepup.verify') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <label for="live_image" style="display:block; margin-bottom:0.5rem; font-weight:bold;">Capture/Upload Live Image:</label>
            <input type="file" name="live_image" accept="image/*" required style="margin-bottom:1rem;">
            <br>
            <button type="submit" style="padding:10px 20px; font-size:16px; background-color:#007bff; color:white; border:none; border-radius:4px; cursor:pointer;">
                Verify Identity
            </button>
        </form>
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
