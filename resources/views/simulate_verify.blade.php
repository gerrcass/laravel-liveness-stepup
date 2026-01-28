<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Simulate Liveness Verify</title>
</head>
<body>
    <h1>Simular verificación de Liveness</h1>
    <p>Usuario: {{ auth()->user()->email }}</p>
    <form method="POST" action="{{ url('/rekognition/verify-liveness') }}">
        @csrf
        <input type="hidden" name="user_id" value="{{ auth()->id() }}">
        <input type="hidden" name="liveness[success]" value="1">
        <button type="submit">Marcar verificado (simulado)</button>
    </form>
</body>
</html>
