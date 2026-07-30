@extends('layouts.client')

@section('title', 'Sitios - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow pt-5">
        <main class="grow" role="content">
            <div class="pb-5">
                <div class="kt-container-fluid flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center flex-wrap gap-1 lg:gap-5">
                        <h1 class="font-medium text-base text-mono">Sitios</h1>
                        <div class="flex items-center flex-wrap gap-1 text-sm">
                            <a class="text-secondary-foreground" href="{{ url('/') }}">Dashboard</a>
                            <span class="text-muted-foreground text-sm">/</span>
                            <span class="text-mono">Sitios</span>
                        </div>
                    </div>
                    <div class="flex items-center flex-wrap gap-3">
                        @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                            <a class="kt-btn kt-btn-primary" href="{{ route('sites.create') }}">Nuevo sitio</a>
                        @endif
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

                    <div class="kt-card">
                        <div class="kt-card-content p-0">
                            <table class="kt-table w-full">
                                <thead>
                                    <tr>
                                        <th class="p-4 text-left text-sm text-secondary-foreground">Dominio</th>
                                        <th class="p-4 text-left text-sm text-secondary-foreground">Tipo</th>
                                        <th class="p-4 text-left text-sm text-secondary-foreground">PHP</th>
                                        <th class="p-4 text-left text-sm text-secondary-foreground">Document root</th>
                                        <th class="p-4 text-left text-sm text-secondary-foreground">Estado</th>
                                        <th class="p-4 text-right text-sm text-secondary-foreground">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($sites as $site)
                                        <tr class="border-t border-input">
                                            <td class="p-4 text-sm text-mono font-medium">
                                                <a class="hover:text-primary" href="{{ route('sites.show', $site) }}">{{ $site->domain }}</a>
                                            </td>
                                            <td class="p-4 text-sm">{{ $site->type }}</td>
                                            <td class="p-4 text-sm">{{ $site->php_version }}</td>
                                            <td class="p-4 text-sm text-secondary-foreground">{{ $site->document_root }}</td>
                                            <td class="p-4 text-sm">
                                                <span class="kt-badge kt-badge-sm {{ $site->status === 'active' ? 'kt-badge-success' : 'kt-badge-warning' }} kt-badge-outline">
                                                    {{ $site->status }}
                                                </span>
                                            </td>
                                            <td class="p-4 text-sm text-right">
                                                @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                                                    <a class="kt-btn kt-btn-sm kt-btn-outline" href="{{ route('sites.edit', $site) }}">Editar</a>
                                                    <form action="{{ route('sites.destroy', $site) }}" method="POST" class="inline" onsubmit="return confirm('Eliminar {{ $site->domain }}?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="kt-btn kt-btn-sm kt-btn-outline kt-btn-destructive" type="submit">Eliminar</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="p-4 text-sm text-secondary-foreground" colspan="6">Todavia no hay sitios creados.</td>
                                        </tr>
                                    @endforelse
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
