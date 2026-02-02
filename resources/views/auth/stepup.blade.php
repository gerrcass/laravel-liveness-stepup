@extends('layouts.app')

@section('title', 'Step-Up Verification')

@section('nav_back')
    <a href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <h1>Step-Up Verification</h1>
    @if($errors->any())
        <div style="color:red">{{ $errors->first() }}</div>
    @endif

    @if(session('status'))
        <div style="color:green">{{ session('status') }}</div>
    @endif

    @if($registrationMethod === 'liveness')
        <div style="margin-bottom: 20px; padding: 15px; background-color: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px;">
            <h3>Face Liveness Verification</h3>
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
            <h3>Image-based Verification</h3>
            <p>You registered using a face image. Please upload a live image for verification.</p>
        </div>
        
        <form action="{{ route('stepup.verify') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <label for="live_image">Capture/Upload Live Image:</label>
            <input type="file" name="live_image" accept="image/*" required>
            <br><br>
            <button type="submit">Verify</button>
        </form>
    @endif

    @if(session('stepup_last_attempt_image_path') || session('stepup_last_attempt_rekognition_response'))
        <hr>
        <h2>Resultado del último intento (imagen enviada)</h2>
        <p><strong>Imagen que seleccionaste:</strong></p>
        @if(session('stepup_last_attempt_image_path'))
            <img src="{{ route('stepup.attempt_image') }}?t={{ time() }}" alt="Imagen del intento" style="max-width:400px; max-height:400px; border:1px solid #ccc;">
        @endif
        @if(session('stepup_last_attempt_rekognition_response'))
            <h3>Respuesta JSON de Rekognition</h3>
            <pre style="background:#f5f5f5; padding:1rem; overflow:auto; max-height:400px;">{{ json_encode(session('stepup_last_attempt_rekognition_response'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @endif
    @endif

    @if($registrationMethod === 'liveness')
        <script>
            let livenessVerificationCompleted = false;

            // Global callback for Face Liveness completion
            window.onLivenessComplete = function(result) {
                if (result.success && result.verified) {
                    // Redirect to intended page or dashboard
                    window.location.href = '{{ session("stepup_intended.url", route("dashboard")) }}';
                } else {
                    alert('Face Liveness verification failed. Please try again.');
                    // Reload the page to start over
                    window.location.reload();
                }
            };

            window.onLivenessError = function(error) {
                console.error('Face Liveness error:', error);
                alert('Error en Face Liveness: ' + (error.message || 'Error desconocido'));
                // Reload the page to start over
                window.location.reload();
            };

            // Initialize Face Liveness component when page loads
            document.addEventListener('DOMContentLoaded', function() {
                if (window.initializeFaceLiveness) {
                    window.initializeFaceLiveness('verification');
                }
            });
        </script>
    @endif
@endsection
