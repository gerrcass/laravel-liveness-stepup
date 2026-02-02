<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Registro de Usuario</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .registration-method {
            margin: 20px 0;
            padding: 20px;
            border: 2px solid #ddd;
            border-radius: 8px;
        }
        .registration-method.active {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
        .method-selector {
            margin-bottom: 20px;
        }
        .method-selector label {
            display: inline-block;
            margin-right: 20px;
            cursor: pointer;
        }
        #liveness-container {
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <h1>Registro de Usuario</h1>
    <form id="registration-form" action="{{ route('register') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <label for="name">Nombre:</label>
        <input type="text" name="name" value="{{ old('name') }}" required>
        <br><br>
        
        <label for="email">Correo Electrónico:</label>
        <input type="email" name="email" value="{{ old('email') }}" required>
        <br><br>
        
        <label for="password">Contraseña:</label>
        <input type="password" name="password" required>
        <br><br>
        
        <label for="password_confirmation">Confirmar Contraseña:</label>
        <input type="password" name="password_confirmation" required>
        <br><br>
        
        <label for="role">Rol:</label>
        <select name="role" id="role" required>
            @foreach($roles as $role)
                <option value="{{ $role->name }}" {{ old('role') === $role->name ? 'selected' : '' }}>{{ ucfirst($role->name) }}</option>
            @endforeach
        </select>
        <br><br>

        <div class="method-selector">
            <h3>Método de Registro Facial:</h3>
            <label>
                <input type="radio" name="registration_method" value="image" checked onchange="toggleRegistrationMethod()">
                Imagen Facial (Tradicional)
            </label>
            <label>
                <input type="radio" name="registration_method" value="liveness" onchange="toggleRegistrationMethod()">
                Face Liveness (Video Selfie)
            </label>
        </div>

        <div id="image-method" class="registration-method active">
            <h4>Subir Imagen Facial</h4>
            <label for="face_image">Imagen Facial:</label>
            <input type="file" name="face_image" accept="image/*">
        </div>

        <div id="liveness-method" class="registration-method" style="display: none;">
            <h4>Face Liveness Registration</h4>
            <div id="liveness-container">
                <p>Face Liveness component will load here when selected.</p>
            </div>
            <input type="hidden" name="liveness_session_id" id="liveness_session_id">
        </div>

        @if($errors->any())
            <div style="color: red; margin: 10px 0;">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <button type="submit" id="submit-btn">Registrar</button>
    </form>

    <script>
        let livenessCompleted = false;

        function toggleRegistrationMethod() {
            const method = document.querySelector('input[name="registration_method"]:checked').value;
            const imageMethod = document.getElementById('image-method');
            const livenessMethod = document.getElementById('liveness-method');
            const submitBtn = document.getElementById('submit-btn');

            if (method === 'image') {
                imageMethod.style.display = 'block';
                imageMethod.classList.add('active');
                livenessMethod.style.display = 'none';
                livenessMethod.classList.remove('active');
                submitBtn.disabled = false;
                
                // Clear liveness data
                document.getElementById('liveness_session_id').value = '';
                livenessCompleted = false;
            } else {
                imageMethod.style.display = 'none';
                imageMethod.classList.remove('active');
                livenessMethod.style.display = 'block';
                livenessMethod.classList.add('active');
                submitBtn.disabled = !livenessCompleted;
                
                // Load Face Liveness component
                loadFaceLivenessComponent();
            }
        }

        function loadFaceLivenessComponent() {
            const container = document.getElementById('liveness-container');
            container.innerHTML = '<div id="face-liveness-root"></div>';
            
            // This will be handled by the React component
            if (window.initializeFaceLiveness) {
                window.initializeFaceLiveness('registration');
            }
        }

        // Handle form submission
        document.getElementById('registration-form').addEventListener('submit', function(e) {
            const method = document.querySelector('input[name="registration_method"]:checked').value;
            
            if (method === 'liveness' && !livenessCompleted) {
                e.preventDefault();
                alert('Por favor complete el proceso de Face Liveness antes de continuar.');
                return false;
            }
            
            if (method === 'image') {
                const fileInput = document.querySelector('input[name="face_image"]');
                if (!fileInput.files.length) {
                    e.preventDefault();
                    alert('Por favor seleccione una imagen facial.');
                    return false;
                }
            }
        });

        // Global callback for Face Liveness completion
        window.onLivenessComplete = function(result) {
            if (result.success) {
                document.getElementById('liveness_session_id').value = result.sessionId || '';
                livenessCompleted = true;
                document.getElementById('submit-btn').disabled = false;
                
                const container = document.getElementById('liveness-container');
                container.innerHTML = '<div style="color: green; text-align: center; padding: 20px;"><h4>✓ Face Liveness completado exitosamente</h4><p>Confidence: ' + (result.confidence || 0) + '%</p></div>';
            } else {
                livenessCompleted = false;
                document.getElementById('submit-btn').disabled = true;
                alert('Face Liveness falló: ' + (result.error || 'Error desconocido'));
            }
        };

        window.onLivenessError = function(error) {
            livenessCompleted = false;
            document.getElementById('submit-btn').disabled = true;
            console.error('Face Liveness error:', error);
            alert('Error en Face Liveness: ' + (error.message || 'Error desconocido'));
        };
    </script>
</body>
</html>