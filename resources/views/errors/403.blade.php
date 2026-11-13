@extends('errors.layout')
@section('code', '403')
@section('title', 'Acceso no autorizado')
@section('message', $exception?->getMessage() ?: 'No tienes permiso para realizar esta operación.')
