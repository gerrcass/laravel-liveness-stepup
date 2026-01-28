<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Usuario</title>
</head>
<body>
    <h1>Registro de Usuario</h1>
    <form action="{{ route('register') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <label for="name">Nombre:</label>
        <input type="text" name="name" required>
        <br>
        <label for="email">Correo Electrónico:</label>
        <input type="email" name="email" required>
        <br>
        <label for="password">Contraseña:</label>
        <input type="password" name="password" required>
        <br>
        <label for="password_confirmation">Confirmar Contraseña:</label>
        <input type="password" name="password_confirmation" required>
        <br>
        <label for="face_image">Imagen Facial:</label>
        <input type="file" name="face_image" accept="image/*" required>
        <br>
        <button type="submit">Registrar</button>
    </form>
</body>
</html>