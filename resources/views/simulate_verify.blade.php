@extends('layouts.app')

@section('title', 'Simular verificación step-up')

@section('content')
    <h1>Simular verificación step-up</h1>
    <p>Usuario: {{ auth()->user()->email }}</p>
    <form method="POST" action="{{ url('/rekognition/mark-stepup-verified') }}">
        @csrf
        <input type="hidden" name="user_id" value="{{ auth()->id() }}">
        <input type="hidden" name="verification[success]" value="1">
        <button type="submit">Marcar verificado (simulado)</button>
    </form>
@endsection
