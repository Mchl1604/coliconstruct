@extends('layouts.errorShell', ['back' => true])

@section('title', 'Access Denied')
@section('code', '403')
@section('heading', 'Access denied.')
@section('message', 'You do not have permission to view this page.')
