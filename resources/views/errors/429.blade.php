@extends('layouts.errorShell', ['back' => true])

@section('title', 'Too Many Requests')
@section('code', '429')
@section('heading', 'Too many requests.')
@section('message', 'Please wait a moment and try again.')
