@extends('layouts.client')

@section('title', 'Builder - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow pt-5">
        <main class="grow" role="content">
            <div class="pb-5">
                <div class="kt-container-fluid flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center flex-wrap gap-1 lg:gap-5">
                        <h1 class="font-medium text-base text-mono">Builder</h1>
                        <div class="flex items-center flex-wrap gap-1 text-sm">
                            <a class="text-secondary-foreground" href="{{ url('/') }}">Dashboard</a>
                            <span class="text-muted-foreground text-sm">/</span>
                            <span class="text-mono">Builder</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="kt-container-fluid">
                <div class="kt-card">
                    <div class="kt-card-content p-10 text-center flex flex-col items-center gap-3">
                        <i class="ki-filled ki-color-swatch text-4xl text-muted-foreground"></i>
                        <h2 class="text-lg font-semibold text-mono">Proximamente</h2>
                        <p class="text-sm text-secondary-foreground max-w-md">
                            Constructor visual de sitios sin escribir codigo. Todavia no implementado.
                        </p>
                    </div>
                </div>
            </div>
            @include('layouts.partials.client.footer')
        </main>
    </div>
</div>
@endsection
