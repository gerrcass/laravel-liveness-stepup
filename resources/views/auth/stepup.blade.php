<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Step-Up Verification</title>
</head>
<body>
    <h1>Step-Up Verification</h1>
    @if($errors->any())
        <div style="color:red">{{ $errors->first() }}</div>
    @endif

    @if(session('status'))
        <div style="color:green">{{ session('status') }}</div>
    @endif

    <form action="{{ route('stepup.verify') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <label for="live_image">Capture/Upload Live Image:</label>
        <input type="file" name="live_image" accept="image/*" required>
        <br>
        <button type="submit">Verify</button>
    </form>

    @if(session('stepup_verification_result'))
        <h3>Last attempt</h3>
        <pre>{{ json_encode(session('stepup_verification_result'), JSON_PRETTY_PRINT) }}</pre>
    @endif
</body>
</html>