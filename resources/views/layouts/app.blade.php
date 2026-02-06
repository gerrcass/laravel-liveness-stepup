<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'App')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: sans-serif; margin: 0; padding: 1rem; }
        .nav { background: #f0f0f0; padding: 0.75rem 1rem; margin: -1rem -1rem 1rem -1rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .nav a, .nav .nav-btn { margin-right: 0.5rem; }
        .nav form { display: inline; }
        .user-info { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .user-face-thumb { width: 64px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid #ccc; vertical-align: middle; }
        .countdown-box { background: #e8f5e9; border: 1px solid #4caf50; padding: 0.5rem 1rem; border-radius: 6px; margin-bottom: 1rem; display: inline-block; }
        .countdown-box.expired { background: #ffebee; border-color: #f44336; }
        
        /* Modal styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: white; padding: 2rem; border-radius: 8px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; }
        .method-selection { margin: 1rem 0; padding: 1rem; border: 1px solid #ccc; border-radius: 4px; }
        .method-option { margin: 0.5rem 0; }
        .modal-buttons { margin-top: 1rem; display: flex; gap: 0.5rem; }
        .btn { padding: 12px 24px; font-size: 16px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-primary { background-color: #007bff; color: white; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .hidden { display: none; }
        .error-message { color: #dc3545; margin-top: 0.5rem; }
        .success-message { color: #28a745; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <nav class="nav">
        @hasSection('nav_back')
            @yield('nav_back')
        @endif
        <a href="{{ route('dashboard') }}">Dashboard</a>
        @if(auth()->user()->hasRole('privileged'))
            <a href="{{ route('simulate.verify') }}">Simular verificación</a>
        @endif
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="nav-btn">Cerrar sesión</button>
        </form>
    </nav>

    @auth
        @php
            $user = auth()->user();
            $userFace = $user->userFace ?? null;
            $hasFaceImage = false;
            $faceImageUrl = null;
            
            if ($userFace) {
                // Check for image method (traditional)
                if (($userFace->face_data['path'] ?? null) || ($userFace->face_data['s3_object'] ?? null)) {
                    $hasFaceImage = true;
                    $faceImageUrl = route('user.registered_face') . '?t=' . time();
                }
                // Check for liveness method
                elseif ($userFace->registration_method === 'liveness' && ($userFace->liveness_data['ReferenceImage']['S3Object'] ?? null)) {
                    $hasFaceImage = true;
                    $faceImageUrl = route('user.registered_face') . '?t=' . time();
                }
            }
        @endphp
        <div class="user-info">
            <span><strong>Bienvenido, {{ $user->name }}</strong> ({{ $user->email }} | id: {{ $user->id }})</span>
            @if($hasFaceImage)
                @php
                    $methodLabel = $userFace && $userFace->registration_method === 'liveness' ? 'liveness' : 'imagen';
                    $collectionName = $userFace->collection_name ?? config('rekognition.collection_name', 'users');
                @endphp
                <span> — Cara registrada ({{ $methodLabel }} en {{ $collectionName }}):</span>
                <img src="{{ $faceImageUrl }}" alt="Cara registrada" class="user-face-thumb" title="Imagen facial con la que te registraste">
            @else
                <span style="color:#888;"> — Sin cara registrada</span>
            @endif
        </div>

        @php
            $verifiedAt = session('stepup_verified_at');
            $timeout = config('stepup.timeout', 900);
            $expiresAt = $verifiedAt ? \Carbon\Carbon::parse($verifiedAt)->addSeconds($timeout)->timestamp : null;
        @endphp
        @if($expiresAt && $expiresAt > time())
            <div class="countdown-box" id="stepup-countdown" role="status" aria-live="polite">
                Verificación facial activa: <strong id="stepup-countdown-text">--</strong> restantes (bypass hasta que expire)
            </div>
            <script>
                (function() {
                    var expiresAt = {{ $expiresAt }};
                    var el = document.getElementById('stepup-countdown-text');
                    var box = document.getElementById('stepup-countdown');
                    function update() {
                        var now = Math.floor(Date.now() / 1000);
                        var left = expiresAt - now;
                        if (left <= 0) {
                            el.textContent = '0 s';
                            box.classList.add('expired');
                            box.innerHTML = 'Verificación facial <strong>expirada</strong>. La próxima operación protegida pedirá verificación de nuevo.';
                            return;
                        }
                        var m = Math.floor(left / 60);
                        var s = left % 60;
                        el.textContent = m + ' min ' + s + ' s';
                        setTimeout(update, 1000);
                    }
                    update();
                })();
            </script>
        @endif
    @endauth

    @yield('content')

    <!-- Face Registration Modal -->
    @if(!$hasFaceImage && auth()->check() && !session('face_registration_completed'))
    <div id="face-registration-modal" class="modal-overlay active">
        <div class="modal-content">
            <div class="modal-header">
                <h2 style="margin: 0;">Registro de Información Facial</h2>
                <button type="button" class="modal-close" onclick="closeFaceModal()">&times;</button>
            </div>
            
            <p>Para acceder a todas las funciones de la aplicación, necesitas registrar tu información facial.</p>
            
            <form id="face-registration-form" enctype="multipart/form-data">
                @csrf
                
                <div class="method-selection">
                    <h3 style="margin-top: 0;">Método de Registro</h3>
                    <div class="method-option">
                        <input type="radio" id="modal_method_image" name="registration_method" value="image" checked>
                        <label for="modal_method_image">Imagen Facial (subir foto)</label>
                    </div>
                    <div class="method-option">
                        <input type="radio" id="modal_method_liveness" name="registration_method" value="liveness">
                        <label for="modal_method_liveness">Face Liveness (video selfie)</label>
                    </div>
                </div>

                <!-- Image upload method -->
                <div id="modal-image-method" class="method-content">
                    <div style="margin: 1rem 0;">
                        <label for="modal_face_image">Selecciona una imagen de tu rostro:</label><br>
                        <input type="file" id="modal_face_image" name="face_image" accept="image/*" required>
                    </div>
                </div>

                <!-- Face Liveness method -->
                <div id="modal-liveness-method" class="method-content hidden">
                    <div id="modal-liveness-container" style="margin: 1rem 0;">
                        <div id="modal-face-liveness-root"></div>
                    </div>
                    <input type="hidden" id="modal_liveness_session_id" name="liveness_session_id" value="">
                </div>

                <div id="modal-form-message" class="error-message"></div>

                <div class="modal-buttons">
                    <button type="submit" id="modal-submit-btn" class="btn btn-primary">Registrar</button>
                    <button type="button" id="modal-cancel-btn" class="btn btn-secondary" onclick="closeFaceModal()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <script>
        let livenessCompleted = false;
        let currentMethod = 'image';

        // Auto-show modal if user has no face registered
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('face-registration-modal');
            if (modal) {
                // Don't auto-show on /register/face page (legacy)
                if (window.location.pathname === '/register/face') {
                    modal.classList.remove('active');
                }
            }
        });

        function closeFaceModal() {
            const modal = document.getElementById('face-registration-modal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function showFaceModal() {
            const modal = document.getElementById('face-registration-modal');
            if (modal) {
                modal.classList.add('active');
            }
        }

        // Method selection
        const modalForm = document.getElementById('face-registration-form');
        if (modalForm) {
            modalForm.querySelectorAll('input[name="registration_method"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    currentMethod = this.value;
                    modalForm.querySelectorAll('.method-content').forEach(el => el.classList.add('hidden'));
                    const methodEl = document.getElementById('modal-' + currentMethod + '-method');
                    if (methodEl) {
                        methodEl.classList.remove('hidden');
                    }
                    
                    if (currentMethod === 'liveness') {
                        loadModalFaceLivenessComponent();
                    }
                });
            });

            // Handle form submission via AJAX
            modalForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const messageEl = document.getElementById('modal-form-message');
                const submitBtn = document.getElementById('modal-submit-btn');
                
                // Validation
                if (currentMethod === 'liveness' && !livenessCompleted) {
                    if (messageEl) {
                        messageEl.textContent = 'Por favor complete el proceso de Face Liveness antes de continuar.';
                        messageEl.className = 'error-message';
                    }
                    return false;
                }
                
                submitBtn.disabled = true;
                submitBtn.textContent = 'Registrando...';
                if (messageEl) {
                    messageEl.textContent = '';
                }
                
                try {
                    const response = await fetch('{{ route("register.face.store") }}', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    
                    const data = await response.json();
                    
                    if (response.ok && data.success) {
                        if (messageEl) {
                            messageEl.textContent = '¡Registro exitoso! Redirigiendo...';
                            messageEl.className = 'success-message';
                        }
                        submitBtn.textContent = '¡Listo!';
                        
                        // Close modal and reload page after short delay
                        setTimeout(() => {
                            closeFaceModal();
                            window.location.reload();
                        }, 1500);
                    } else {
                        if (messageEl) {
                            messageEl.textContent = data.message || 'Error al registrar. Por favor intenta de nuevo.';
                            messageEl.className = 'error-message';
                        }
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Registrar';
                    }
                } catch (error) {
                    if (messageEl) {
                        messageEl.textContent = 'Error de conexión. Por favor intenta de nuevo.';
                        messageEl.className = 'error-message';
                    }
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Registrar';
                }
            });
        }

        // Liveness callbacks - only define if not already defined by page-specific script
        if (typeof window.onLivenessComplete === 'undefined') {
            window.onLivenessComplete = function(result) {
                if (result.success) {
                    livenessCompleted = true;
                    // Only set values if modal form exists (registration modal)
                    const sessionInput = document.getElementById('modal_liveness_session_id');
                    const submitBtn = document.getElementById('modal-submit-btn');
                    if (sessionInput) {
                        sessionInput.value = result.sessionId;
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                }
            };
        }

        // Liveness error callback - only define if not already defined by page-specific script
        if (typeof window.onLivenessError === 'undefined') {
            window.onLivenessError = function(error) {
                console.error('Face Liveness error:', error);
            };
        }
    </script>
</body>
</html>
