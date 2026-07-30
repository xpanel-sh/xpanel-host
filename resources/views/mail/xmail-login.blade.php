@extends('layouts.xmail')

@section('title', 'Iniciar sesión - XMail')

@section('content')
<main class="flex min-h-full items-center justify-center p-6">
    <div class="kt-card w-full max-w-md shadow-lg">
        <div class="kt-card-content p-8">
            <div class="mb-7 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary"><i class="ki-filled ki-sms text-2xl"></i></span>
                <h1 class="mt-4 text-2xl font-semibold text-mono">XMail</h1>
                <p class="mt-2 text-sm text-secondary-foreground">Accede con la dirección completa y la contraseña de tu buzón.</p>
            </div>

            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('xmail.authenticate') }}" class="space-y-5">
                @csrf
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-mono">Correo</span>
                    <input class="kt-input w-full" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required autofocus placeholder="usuario@dominio.com">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-mono">Contraseña del buzón</span>
                    <input class="kt-input w-full" type="password" name="password" autocomplete="current-password" required>
                </label>
                <button class="kt-btn kt-btn-primary w-full justify-center" type="submit">Entrar a XMail</button>
            </form>

            <p class="mt-6 text-center text-xs text-secondary-foreground">XMail y Roundcube usan el mismo buzón. Tus mensajes no se duplican.</p>
        </div>
    </div>
</main>
@endsection
