<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    </head>
<body>
    <h1>Iniciar sesión</h1>
    @if($errors->any())
        <div style="color:red">{{ $errors->first() }}</div>
    @endif
    <form action="{{ url('/login') }}" method="POST">
        @csrf
        <label>Email:</label>
        <input type="email" name="email" required>
        <br>
        <label>Password:</label>
        <input type="password" name="password" required>
        <br>
        <button type="submit">Login</button>
    </form>
    <p>¿No tienes una cuenta? <a href="{{ url('/register') }}">Regístrate aquí</a></p>
</body>
</html>
