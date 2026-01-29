@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1>Dashboard</h1>

    @if(auth()->user()->hasRole('privileged'))
        <form method="POST" action="{{ route('special.operation') }}" style="display:inline;">
            @csrf
            <button type="submit">Perform Special Operation (requires step-up)</button>
        </form>
    @else
        <p style="color:gray;">No tienes privilegios para la operación especial.</p>
    @endif
@endsection
