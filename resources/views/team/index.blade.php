@extends('layouts.client')

@section('title', 'Equipo - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow pt-5">
        <main class="grow" role="content">
            <div class="pb-5">
                <div class="kt-container-fluid flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center flex-wrap gap-1 lg:gap-5">
                        <h1 class="font-medium text-base text-mono">Equipo</h1>
                        <div class="flex items-center flex-wrap gap-1 text-sm">
                            <a class="text-secondary-foreground" href="{{ url('/') }}">Dashboard</a>
                            <span class="text-muted-foreground text-sm">/</span>
                            <span class="text-mono">Equipo</span>
                        </div>
                    </div>
                    <div class="flex items-center flex-wrap gap-3">
                        <a class="kt-btn kt-btn-outline" href="{{ route('roles.index') }}">Roles</a>
                        <a class="kt-btn kt-btn-primary" href="{{ route('team.create') }}">Invitar usuario</a>
                    </div>
                </div>
            </div>

            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">
                    @if (session('status'))
                        <div class="flex items-center gap-2 rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success">
                            {{ session('status') }}
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="flex items-center gap-2 rounded-lg bg-danger/10 border border-danger/20 px-4 py-3 text-sm text-danger">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <div class="kt-card">
                        <div class="kt-card-content p-0">
                            <table class="kt-table w-full">
                                <thead>
                                    <tr>
                                        <th class="p-4 text-left text-sm text-secondary-foreground">Nombre</th>
                                        <th class="p-4 text-left text-sm text-secondary-foreground">Correo</th>
                                        <th class="p-4 text-left text-sm text-secondary-foreground">Rol</th>
                                        <th class="p-4 text-right text-sm text-secondary-foreground">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($users as $member)
                                        <tr class="border-t border-input">
                                            <td class="p-4 text-sm text-mono font-medium">{{ $member->name }}</td>
                                            <td class="p-4 text-sm text-secondary-foreground">{{ $member->email }}</td>
                                            <td class="p-4 text-sm">
                                                <span class="kt-badge kt-badge-sm kt-badge-primary kt-badge-outline">{{ $member->role?->name ?? 'Sin rol' }}</span>
                                            </td>
                                            <td class="p-4 text-sm text-right">
                                                <a class="kt-btn kt-btn-sm kt-btn-outline" href="{{ route('team.edit', $member) }}">Editar</a>
                                                <form action="{{ route('team.destroy', $member) }}" method="POST" class="inline" onsubmit="return confirm('Eliminar a {{ $member->name }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="kt-btn kt-btn-sm kt-btn-outline kt-btn-destructive" type="submit">Eliminar</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            @include('layouts.partials.client.footer')
        </main>
    </div>
</div>
@endsection
