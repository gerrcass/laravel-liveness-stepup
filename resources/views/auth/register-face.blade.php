<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro Facial</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: sans-serif; margin: 0; padding: 1rem; }
        .container { max-width: 600px; margin: 0 auto; }
        .method-selection { margin: 1rem 0; padding: 1rem; border: 1px solid #ccc; border-radius: 4px; }
        .method-option { margin: 0.5rem 0; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Registro de Información Facial</h1>
        
        <p>Para acceder a todas las funciones de la aplicación, necesitas registrar tu información facial.</p>

        <form id="registration-form" method="POST" action="{{ route('register.face.store') }}">
            @csrf
            
            <div class="method-selection">
                <h3>Método de Registro</h3>
                <div class="method-option">
                    <input type="radio" id="method_image" name="registration_method" value="image" checked>
                    <label for="method_image">Imagen Facial (subir foto)</label>
                </div>
                <div class="method-option">
                    <input type="radio" id="method_liveness" name="registration_method" value="liveness">
                    <label for="method_liveness">Face Liveness (video selfie)</label>
                </div>
            </div>

            <!-- Image upload method -->
            <div id="image-method" class="method-content">
                <div style="margin: 1rem 0;">
                    <label for="face_image">Selecciona una imagen de tu rostro:</label><br>
                    <input type="file" id="face_image" name="face_image" accept="image/*" required>
                </div>
            </div>

            <!-- Face Liveness method -->
            <div id="liveness-method" class="method-content hidden">
                <div id="liveness-container" style="margin: 1rem 0;">
                    <div id="face-liveness-root"></div>
                </div>
                <input type="hidden" id="liveness_session_id" name="liveness_session_id" value="">
            </div>

            <button type="submit" id="submit-btn" style="padding: 12px 24px; font-size: 16px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                Registrar
            </button>
        </form>

        <p style="margin-top: 1rem;">
            <a href="{{ url('/logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Cerrar sesión</a>
        </p>
        <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display: none;">
            @csrf
        </form>
    </div>

    <script>
        let livenessCompleted = false;
        let currentMethod = 'image';

        // Method selection
        document.querySelectorAll('input[name="registration_method"]').forEach(radio => {
            radio.addEventListener('change', function() {
                currentMethod = this.value;
                document.querySelectorAll('.method-content').forEach(el => el.classList.add('hidden'));
                document.getElementById(currentMethod + '-method').classList.remove('hidden');
                
                if (currentMethod === 'liveness') {
                    loadFaceLivenessComponent();
                }
            });
        });

        function loadFaceLivenessComponent() {
            const container = document.getElementById('liveness-container');
            if (container.querySelector('#face-liveness-root')) return; // Already loaded
            
            container.innerHTML = '<div id="face-liveness-root"></div>';
            
            if (window.initializeFaceLiveness) {
                window.initializeFaceLiveness('registration', { threshold: {{ config('rekognition.confidence_threshold', 85.0) }} });
            }
        }

        // Handle form submission
        document.getElementById('registration-form').addEventListener('submit', function(e) {
            if (currentMethod === 'liveness' && !livenessCompleted) {
                e.preventDefault();
                alert('Por favor complete el proceso de Face Liveness antes de continuar.');
                return false;
            }
        });

        window.onLivenessComplete = function(result) {
            if (result.success) {
                livenessCompleted = true;
                document.getElementById('liveness_session_id').value = result.sessionId;
                document.getElementById('submit-btn').disabled = false;
            }
        };

        window.onLivenessError = function(error) {
            console.error('Face Liveness error:', error);
        };
    </script>
</body>
</html>
