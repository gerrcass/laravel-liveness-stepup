@extends('layouts.app')

@section('title', 'Step-Up Verification')

@section('nav_back')
    <a href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
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
@endsection
