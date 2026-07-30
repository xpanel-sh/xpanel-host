<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="es">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1, shrink-to-fit=no" name="viewport"/>
    <title>Configuracion inicial - xpanel-host</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}" rel="stylesheet"/>
    <link href="{{ asset('assets/css/styles.css') }}" rel="stylesheet"/>
</head>
<body class="antialiased flex h-full text-base text-foreground bg-background">
    <div class="flex items-center justify-center grow bg-center bg-no-repeat">
        <div class="kt-card max-w-[420px] w-full">
            <div class="kt-card-content flex flex-col gap-5 p-10">
                <div class="text-center mb-2.5">
                    <div class="text-2xl font-bold text-mono mb-1">xpanel-host</div>
                    <h3 class="text-lg font-medium text-mono leading-none mb-2.5">Configuracion inicial</h3>
                    <span class="text-sm text-secondary-foreground">Crea la cuenta Owner de este hosting. Es la unica vez que se pide.</span>
                </div>

                @if ($errors->any())
                    <div class="flex items-center gap-2 rounded-lg bg-danger/10 border border-danger/20 px-4 py-3 text-sm text-danger">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form action="{{ route('setup') }}" method="POST" class="flex flex-col gap-5">
                    @csrf

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Nombre</label>
                        <input class="kt-input" type="text" name="name" value="{{ old('name') }}" placeholder="Tu nombre" autofocus required/>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Correo electronico</label>
                        <input class="kt-input" type="email" name="email" value="{{ old('email') }}" placeholder="owner@tudominio.com" required/>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Contrasena</label>
                        <input class="kt-input" name="password" placeholder="Minimo 8 caracteres" type="password" minlength="8" required/>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Confirmar contrasena</label>
                        <input class="kt-input" name="password_confirmation" placeholder="Repite la contrasena" type="password" minlength="8" required/>
                    </div>

                    <button type="submit" class="kt-btn kt-btn-primary flex justify-center grow">Crear cuenta Owner</button>
                </form>
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/js/core.bundle.js') }}"></script>
    <script src="{{ asset('assets/vendors/ktui/ktui.min.js') }}"></script>
</body>
</html>
