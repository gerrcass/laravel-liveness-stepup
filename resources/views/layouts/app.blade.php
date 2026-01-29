<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'App')</title>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 1rem; }
        .nav { background: #f0f0f0; padding: 0.75rem 1rem; margin: -1rem -1rem 1rem -1rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .nav a, .nav .nav-btn { margin-right: 0.5rem; }
        .nav form { display: inline; }
        .user-info { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .user-face-thumb { width: 64px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid #ccc; vertical-align: middle; }
        .countdown-box { background: #e8f5e9; border: 1px solid #4caf50; padding: 0.5rem 1rem; border-radius: 6px; margin-bottom: 1rem; display: inline-block; }
        .countdown-box.expired { background: #ffebee; border-color: #f44336; }
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
        <div class="user-info">
            <span><strong>Bienvenido, {{ auth()->user()->name }}</strong> ({{ auth()->user()->email }})</span>
            @if(auth()->user()->userFace && (auth()->user()->userFace->face_data['path'] ?? null))
                <span> — Cara registrada:</span>
                <img src="{{ route('user.registered_face') }}?t={{ time() }}" alt="Cara registrada" class="user-face-thumb" title="Imagen facial con la que te registraste (para referencia en pruebas)">
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
</body>
</html>
