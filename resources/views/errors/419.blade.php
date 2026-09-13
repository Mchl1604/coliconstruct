@extends('layouts.errorShell', ['back' => true, 'signIn' => true])

@section('title', 'Session Expired')
@section('code', '419')
@section('heading', 'Session expired.')
@section('message', 'Please refresh the page and try again.')
