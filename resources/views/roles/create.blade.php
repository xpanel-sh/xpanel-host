@extends('layouts.client')

@section('title', 'Nuevo rol - xpanel-host')

@section('content')
<div class="flex grow items-center justify-center lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="kt-card max-w-[480px] w-full">
        <div class="kt-card-content flex flex-col gap-5 p-10">
            <div class="mb-2.5">
                <h3 class="text-lg font-medium text-mono leading-none mb-2.5">Nuevo rol</h3>
                <span class="text-sm text-secondary-foreground">Define un rol propio con los permisos que necesites.</span>
            </div>

            @if ($errors->any())
                <div class="flex items-center gap-2 rounded-lg bg-danger/10 border border-danger/20 px-4 py-3 text-sm text-danger">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('roles.store') }}" method="POST" class="flex flex-col gap-5">
                @csrf

                <div class="flex flex-col gap-1">
                    <label class="kt-form-label font-normal text-mono">Nombre del rol</label>
                    <input class="kt-input" type="text" name="name" value="{{ old('name') }}" placeholder="Ej: Soporte" autofocus required/>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="kt-form-label font-normal text-mono">Permisos</label>
                    @foreach ($permissions as $key => $label)
                        <label class="kt-label">
                            <input class="kt-checkbox kt-checkbox-sm" type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, old('permissions', [])))/>
                            <span class="kt-checkbox-label">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="kt-btn kt-btn-primary flex justify-center grow">Crear rol</button>
                    <a href="{{ route('roles.index') }}" class="kt-btn kt-btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
