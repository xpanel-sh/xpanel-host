<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="es">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1, shrink-to-fit=no" name="viewport"/>
    <title>{{ $appName }} - Iniciar sesion</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}" rel="stylesheet"/>
    <link href="{{ asset('assets/css/styles.css') }}" rel="stylesheet"/>
</head>
<body class="antialiased flex h-full text-base text-foreground bg-background">
    <script>
        const defaultThemeMode = 'light';
        let themeMode;
        if (document.documentElement) {
            if (localStorage.getItem('kt-theme')) {
                themeMode = localStorage.getItem('kt-theme');
            } else if (document.documentElement.hasAttribute('data-kt-theme-mode')) {
                themeMode = document.documentElement.getAttribute('data-kt-theme-mode');
            } else {
                themeMode = defaultThemeMode;
            }
            if (themeMode === 'system') {
                themeMode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.classList.add(themeMode);
        }
    </script>

    <div class="flex items-center justify-center grow bg-center bg-no-repeat">
        <div class="kt-card max-w-[370px] w-full">
            <div class="kt-card-content flex flex-col gap-5 p-10">
                <div class="text-center mb-2.5">
                    <div class="text-2xl font-bold text-mono mb-1">{{ $appName }}</div>
                    <h3 class="text-lg font-medium text-mono leading-none mb-2.5">Iniciar sesion</h3>
                    <div class="flex items-center justify-center font-medium">
                        <span class="text-sm text-secondary-foreground me-1.5">Panel de hosting</span>
                    </div>
                </div>

                @if ($errors->any())
                    <div class="flex items-center gap-2 rounded-lg bg-danger/10 border border-danger/20 px-4 py-3 text-sm text-danger">
                        <i class="ki-filled ki-information-2 text-base shrink-0"></i>
                        {{ $errors->first() }}
                    </div>
                @endif

                @if (session('status'))
                    <div class="flex items-center gap-2 rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success">
                        <i class="ki-filled ki-check-circle text-base shrink-0"></i>
                        {{ session('status') }}
                    </div>
                @endif

                <form action="{{ route('login') }}" method="POST" class="flex flex-col gap-5">
                    @csrf

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Correo electronico</label>
                        <input class="kt-input" type="email" name="email" value="{{ old('email') }}" placeholder="correo@ejemplo.com" autofocus required/>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Contrasena</label>
                        <div class="kt-input" data-kt-toggle-password="true">
                            <input name="password" placeholder="********" type="password" required/>
                            <button class="kt-btn kt-btn-sm kt-btn-ghost kt-btn-icon bg-transparent! -me-1.5" data-kt-toggle-password-trigger="true" type="button">
                                <span class="kt-toggle-password-active:hidden">
                                    <i class="ki-filled ki-eye text-muted-foreground"></i>
                                </span>
                                <span class="hidden kt-toggle-password-active:block">
                                    <i class="ki-filled ki-eye-slash text-muted-foreground"></i>
                                </span>
                            </button>
                        </div>
                    </div>

                    <label class="kt-label">
                        <input class="kt-checkbox kt-checkbox-sm" name="remember" type="checkbox" value="1"/>
                        <span class="kt-checkbox-label">Recordarme</span>
                    </label>

                    <button type="submit" class="kt-btn kt-btn-primary flex justify-center grow">Iniciar sesion</button>
                </form>
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/js/core.bundle.js') }}"></script>
    <script src="{{ asset('assets/vendors/ktui/ktui.min.js') }}"></script>
</body>
</html>

