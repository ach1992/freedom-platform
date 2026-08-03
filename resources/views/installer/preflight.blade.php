@extends('installer.layout')

@section('title', __('installer.preflight'))

@section('content')
    <h1>{{ __('installer.preflight') }}</h1>
    <ul>
        @foreach ($checks as $name => $passed)
            <li class="{{ $passed ? 'passed' : 'failed' }}">
                {{ $name }}: {{ $passed ? __('installer.passed') : __('installer.failed') }}
            </li>
        @endforeach
    </ul>
@endsection
