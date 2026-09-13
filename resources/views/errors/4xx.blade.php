@extends('layouts.errorShell', ['back' => true])

@section('title', 'Request Error')
@section('code', $exception->getStatusCode())
@section('heading', 'This request could not be completed.')
@section('message', 'Please go back and try again.')
