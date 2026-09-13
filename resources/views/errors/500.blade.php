@extends('layouts.errorShell', ['retry' => true])

@section('title', 'Server Error')
@section('code', '500')
@section('heading', 'Something went wrong.')
@section('message', 'Please try again in a moment.')
