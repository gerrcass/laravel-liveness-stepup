<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Proceeding...</title>
</head>
<body>
    <p>Completing action — please wait...</p>
    <form id="stepup-replay" method="POST" action="{{ $targetUrl }}">
        @csrf
        @foreach($inputs as $key => $value)
            @if(is_array($value))
                {{-- flatten arrays as JSON --}}
                <input type="hidden" name="{{ $key }}" value='{{ json_encode($value) }}'>
            @else
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
    </form>
    <script>document.getElementById('stepup-replay').submit();</script>
</body>
</html>
