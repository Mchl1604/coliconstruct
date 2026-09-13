@extends('layouts.errorShell', ['retry' => true, 'home' => false])

@section('title', 'Service Unavailable')
@section('code', '503')
@section('heading', 'Service unavailable.')
@section('message', 'We will be back shortly.')
