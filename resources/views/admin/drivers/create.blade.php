@extends('admin.layout')
@section('title','Nuevo conductor')
@section('content')
<div style="margin-bottom:14px"><a href="{{ route('admin.drivers.index') }}" class="muted">← Volver a conductores</a></div>
<form method="POST" action="{{ route('admin.drivers.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.drivers._form')
</form>
@endsection
