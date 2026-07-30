@extends('layouts.client')

@section('title', "Editar {$member->name} - xpanel-host")

@section('content')
<div class="flex grow items-center justify-center lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="kt-card max-w-[480px] w-full">
        <div class="kt-card-content flex flex-col gap-5 p-10">
            <div class="mb-2.5">
                <h3 class="text-lg font-medium text-mono leading-none mb-2.5">Editar {{ $member->name }}</h3>
                <span class="text-sm text-secondary-foreground">{{ $member->email }}</span>
            </div>

            @if ($errors->any())
                <div class="flex items-center gap-2 rounded-lg bg-danger/10 border border-danger/20 px-4 py-3 text-sm text-danger">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('team.update', $member) }}" method="POST" class="flex flex-col gap-5">
                @csrf
                @method('PUT')

                <div class="flex flex-col gap-1">
                    <label class="kt-form-label font-normal text-mono">Rol</label>
                    <select class="kt-select" name="role_id">
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected(old('role_id', $member->role_id) == $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="kt-form-label font-normal text-mono">Nueva contrasena (opcional)</label>
                    <input class="kt-input" type="password" name="password" minlength="8" placeholder="Dejar en blanco para no cambiarla"/>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="kt-btn kt-btn-primary flex justify-center grow">Guardar cambios</button>
                    <a href="{{ route('team.index') }}" class="kt-btn kt-btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
