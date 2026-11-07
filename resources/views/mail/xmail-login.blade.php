@extends('layouts.xmail')

@section('title', 'Iniciar sesión - XMail')

@section('content')
<main class="flex min-h-full grow items-center justify-center bg-center bg-no-repeat p-5">
    <div class="kt-card w-full max-w-[370px]">
        <div class="kt-card-content flex flex-col gap-5 p-8 lg:p-10">
            <div class="mb-7 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary"><i class="ki-filled ki-sms text-2xl"></i></span>
                <h1 class="mt-4 text-2xl font-semibold text-mono">XMail</h1>
                <p class="mt-2 text-sm text-secondary-foreground">Accede con la dirección completa y la contraseña de tu buzón.</p>
            </div>

            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('xmail.authenticate') }}" class="flex flex-col gap-5">
                @csrf
                <label class="flex flex-col gap-1">
                    <span class="kt-form-label font-normal text-mono">Correo</span>
                    <input class="kt-input w-full" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required autofocus placeholder="usuario@dominio.com">
                </label>
                <label class="flex flex-col gap-1">
                    <span class="kt-form-label font-normal text-mono">Contraseña del buzón</span>
                    <div class="kt-input" data-kt-toggle-password="true">
                        <input type="password" name="password" autocomplete="current-password" required>
                        <button class="kt-btn kt-btn-sm kt-btn-ghost kt-btn-icon bg-transparent! -me-1.5" data-kt-toggle-password-trigger="true" type="button"><span class="kt-toggle-password-active:hidden"><i class="ki-filled ki-eye text-muted-foreground"></i></span><span class="hidden kt-toggle-password-active:block"><i class="ki-filled ki-eye-slash text-muted-foreground"></i></span></button>
                    </div>
                </label>
                <button class="kt-btn kt-btn-primary w-full justify-center" type="submit">Entrar a XMail</button>
            </form>

            <p class="mt-6 text-center text-xs text-secondary-foreground">XMail y Roundcube usan el mismo buzón. Tus mensajes no se duplican.</p>
        </div>
    </div>
</main>
@endsection
