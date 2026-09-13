@extends('layouts.errorShell', ['retry' => true])

@section('title', 'Server Error')
@section('code', $exception->getStatusCode())
@section('heading', 'Something went wrong.')
@section('message', 'Please try again in a moment.')
