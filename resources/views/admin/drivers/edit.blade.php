@extends('admin.layout')
@section('title','Editar conductor')
@section('content')
<div style="margin-bottom:14px"><a href="{{ route('admin.drivers.show',$driver) }}" class="muted">← Volver al conductor</a></div>
<form method="POST" action="{{ route('admin.drivers.update',$driver) }}" enctype="multipart/form-data">
    @csrf @method('PUT')
    @include('admin.drivers._form')
</form>
@endsection
