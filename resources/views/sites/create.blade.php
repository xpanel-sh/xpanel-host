@extends('layouts.client')

@section('title', 'Nuevo sitio - xpanel-host')

@section('content')
<div class="flex grow items-center justify-center lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="kt-card max-w-[520px] w-full">
        <div class="kt-card-content flex flex-col gap-5 p-10">
            <div class="mb-2.5">
                <h3 class="text-lg font-medium text-mono leading-none mb-2.5">Nuevo sitio</h3>
                <span class="text-sm text-secondary-foreground">Crea un sitio y su vhost en este servidor.</span>
            </div>

            <form action="{{ route('sites.store') }}" method="POST" class="flex flex-col gap-5">
                @csrf
                @include('sites._form')

                <div class="flex items-center gap-3">
                    <button type="submit" class="kt-btn kt-btn-primary flex justify-center grow">Crear sitio</button>
                    <a href="{{ route('sites.index') }}" class="kt-btn kt-btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
