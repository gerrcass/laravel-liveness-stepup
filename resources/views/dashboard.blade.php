<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
</head>
<body>
    <h1>Dashboard</h1>
    <p>Welcome, {{ auth()->user()->name }}</p>

    <form method="POST" action="{{ route('logout') }}" style="display:inline">
        @csrf
        <button type="submit">Logout</button>
    </form>

    @if(auth()->user()->hasRole('privileged'))
        <form method="POST" action="{{ route('special.operation') }}" style="display:inline; margin-left:1rem">
            @csrf
            <button type="submit">Perform Special Operation (requires step-up)</button>
        </form>
    @else
        <p style="color:gray; margin-top:1rem">You do not have privileges to perform the special operation.</p>
    @endif
</body>
</html>