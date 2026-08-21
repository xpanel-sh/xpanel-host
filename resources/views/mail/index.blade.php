@extends('layouts.client')

@section('title', 'Correos - xpanel-host')

@php
    $canManage = auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE);
    $webmailHostname = config('xpanel.webmail_hostname');
    $webmailUrl = config('xpanel.webmail_url') ?: ($webmailHostname ? 'http://'.$webmailHostname : null);
    $roundcubeEnabled = config('xpanel.roundcube_enabled') && $webmailUrl;
    $xmailEnabled = config('xpanel.xmail_enabled');
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">

                    <div class="flex flex-col gap-4 rounded-2xl border border-border bg-muted/30 p-5 lg:flex-row lg:items-center lg:justify-between lg:p-6">
                        <div class="flex items-center gap-4">
                            <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary"><i class="ki-filled ki-sms text-2xl"></i></span>
                            <div>
                                <div class="flex flex-wrap items-center gap-2"><h1 class="text-2xl font-semibold text-mono">Centro de correo</h1><span class="kt-badge kt-badge-success kt-badge-outline">Servicio activo</span></div>
                                <p class="mt-1 text-sm text-secondary-foreground">Buzones, acceso webmail, entrega y DNS desde un solo lugar.</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($roundcubeEnabled)
                                <a href="{{ $webmailUrl }}" target="_blank" rel="noopener" class="kt-btn kt-btn-outline">
                                    <i class="ki-filled ki-abstract-26"></i>
                                    Abrir Roundcube
                                </a>
                            @else
                                <button type="button" class="kt-btn kt-btn-outline opacity-50 cursor-not-allowed" disabled title="Configura XPANEL_WEBMAIL_HOSTNAME e instala Roundcube">
                                    Roundcube no configurado
                                </button>
                            @endif
                            @if ($xmailEnabled)
                                <a href="{{ route('xmail.login') }}" target="_blank" rel="noopener" class="kt-btn kt-btn-outline">
                                    <i class="ki-filled ki-sms"></i>
                                    Abrir XMail
                                </a>
                            @endif
                            @if ($canManage)
                                <a href="{{ route('mail.create') }}" class="kt-btn kt-btn-primary">
                                    <i class="ki-filled ki-plus"></i>
                                    Crear correo
                                </a>
                            @endif
                        </div>
                    </div>

                    @if (session('status'))
                        <div class="flex items-center gap-3 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm font-medium text-success">
                            <i class="ki-filled ki-check-circle text-lg"></i>
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="kt-card"><div class="kt-card-content flex items-center gap-4 p-5"><span class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><i class="ki-filled ki-sms"></i></span><div><div class="text-2xl font-semibold text-mono">{{ $accounts->total() }}</div><div class="text-xs text-secondary-foreground">Buzones · {{ $mailStats['active'] }} activos</div></div></div></div>
                        <div class="kt-card"><div class="kt-card-content flex items-center gap-4 p-5"><span class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><i class="ki-filled ki-global"></i></span><div><div class="text-2xl font-semibold text-mono">{{ $mailStats['domains'] }}</div><div class="text-xs text-secondary-foreground">Dominios con correo</div></div></div></div>
                        <div class="kt-card"><div class="kt-card-content flex items-center gap-4 p-5"><span class="flex size-10 items-center justify-center rounded-xl bg-success/10 text-success"><i class="ki-filled ki-send"></i></span><div><div class="font-semibold text-mono">SMTP 587</div><div class="text-xs text-secondary-foreground">Envío con STARTTLS</div></div></div></div>
                        <div class="kt-card"><div class="kt-card-content flex items-center gap-4 p-5"><span class="flex size-10 items-center justify-center rounded-xl bg-warning/10 text-warning"><i class="ki-filled ki-cloud"></i></span><div><div class="font-semibold text-mono">IMAP 993</div><div class="text-xs text-secondary-foreground">Recepción cifrada</div></div></div></div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <div class="kt-card lg:col-span-2"><div class="kt-card-content flex flex-col gap-3 p-5 sm:flex-row sm:items-center"><span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-secondary-foreground"><i class="ki-filled ki-router"></i></span><div class="min-w-0 grow"><div class="text-xs text-secondary-foreground">Servidor de correo</div><div class="truncate font-semibold text-mono">{{ config('xpanel.mail_hostname') ?: 'Sin configurar' }}</div></div><span class="kt-badge kt-badge-outline">{{ $mailStats['dedicated_ips'] }} IP dedicada(s)</span></div></div>
                        <div class="kt-card"><div class="kt-card-content grid gap-2 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-secondary-foreground">Acceso webmail</div>@if($xmailEnabled)<a class="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm font-medium hover:border-primary" href="{{ route('xmail.login') }}" target="_blank"><span class="flex items-center gap-2"><i class="ki-filled ki-sms text-primary"></i>XMail</span><i class="ki-filled ki-exit-right-corner"></i></a>@endif @if($roundcubeEnabled)<a class="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm font-medium hover:border-primary" href="{{ $webmailUrl }}" target="_blank" rel="noopener"><span class="flex items-center gap-2"><i class="ki-filled ki-abstract-26 text-primary"></i>Roundcube</span><i class="ki-filled ki-exit-right-corner"></i></a>@endif</div></div>
                    </div>

                    @if (config('xpanel.management_mode') === 'vm')
                        <div class="rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning">En modo VM, SMTP/IMAP necesitan una IP dedicada o reglas TCP/NAT en el servidor padre. Traefik HTTP no publica automáticamente los puertos de correo.</div>
                    @else
                        <div class="rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm text-secondary-foreground">Para recibir correo desde Internet configura MX hacia <strong>{{ config('xpanel.mail_hostname') }}</strong>, un registro A/AAAA, PTR/rDNS y SPF. Emite también SSL para ese hostname desde un sitio con el mismo dominio para reemplazar el certificado inicial del servidor de correo.</div>
                    @endif

                    @if ($canManage)
                        <details class="kt-card">
                            <summary class="kt-card-header cursor-pointer list-none"><div><h2 class="kt-card-title">Configuración avanzada de envío</h2><p class="mt-1 text-xs text-secondary-foreground">IPs dedicadas, PTR y HELO por dominio</p></div><i class="ki-filled ki-down"></i></summary>
                            <div class="kt-card-content border-t border-border p-5 grid gap-4">
                                <p class="text-sm text-secondary-foreground">Da de alta aquí una IPv4 que ya esté asignada y funcionando en la interfaz de red del servidor (el panel no la asigna por ti), junto con el hostname que va a tener su PTR. Después asígnala a uno o varios dominios desde "DNS del correo" en la tabla de abajo.</p>
                                @if ($serverIpAddresses->isNotEmpty())
                                    <div class="overflow-x-auto rounded-xl border border-border">
                                        <table class="kt-table w-full">
                                            <thead><tr><th class="p-3 text-left">IP</th><th class="p-3 text-left">PTR/HELO</th><th class="p-3 text-left">Etiqueta</th><th class="p-3"></th></tr></thead>
                                            <tbody>
                                                @foreach ($serverIpAddresses as $ip)
                                                    <tr class="border-t border-border">
                                                        <td class="p-3 font-mono">{{ $ip->ip_address }}</td>
                                                        <td class="p-3 font-mono">{{ $ip->ptr_hostname }}</td>
                                                        <td class="p-3">{{ $ip->label }}</td>
                                                        <td class="p-3 text-right">
                                                            <form method="POST" action="{{ route('server-ip-addresses.destroy', $ip) }}" onsubmit="return confirm('¿Eliminar esta IP dedicada?')">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="kt-btn kt-btn-sm kt-btn-outline">Eliminar</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                <form method="POST" action="{{ route('server-ip-addresses.store') }}" class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div class="flex flex-col gap-1">
                                        <label class="kt-form-label font-normal text-mono text-xs">IPv4</label>
                                        <input class="kt-input" type="text" name="ip_address" placeholder="203.0.113.10" required/>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="kt-form-label font-normal text-mono text-xs">Hostname PTR/HELO</label>
                                        <input class="kt-input" type="text" name="ptr_hostname" placeholder="mail.cliente.com" required/>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="kt-form-label font-normal text-mono text-xs">Etiqueta (opcional)</label>
                                        <input class="kt-input" type="text" name="label" placeholder="Cliente X"/>
                                    </div>
                                    <button type="submit" class="kt-btn kt-btn-primary">Agregar IP</button>
                                </form>
                            </div>
                        </details>
                    @endif

                    @if ($accounts->isEmpty())
                        <div class="kt-card">
                            <div class="flex flex-col items-center justify-center gap-5 py-20 text-center">
                                <span class="flex size-20 items-center justify-center rounded-full bg-muted">
                                    <i class="ki-filled ki-sms text-4xl text-secondary-foreground opacity-60"></i>
                                </span>
                                <div>
                                    <h2 class="text-lg font-semibold text-mono">No tienes cuentas de correo todavia</h2>
                                    <p class="mt-1 text-sm text-secondary-foreground">Crea una cuenta de correo para uno de tus dominios.</p>
                                </div>
                                @if ($canManage)
                                    <a href="{{ route('mail.create') }}" class="kt-btn kt-btn-primary">
                                        <i class="ki-filled ki-plus"></i>
                                        Crear correo
                                    </a>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="kt-card overflow-visible">
                            <div class="kt-card-header min-h-14 flex-wrap gap-3 py-3">
                                <div>
                                    <h2 class="kt-card-title">Cuentas de correo</h2>
                                    <p class="mt-0.5 text-xs text-secondary-foreground">Buzones configurados en Postfix y Dovecot</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <form action="{{ route('mail.index') }}" method="GET" class="flex items-center gap-2">
                                        <input class="kt-input h-9 w-52" name="search" type="search" value="{{ $search }}" placeholder="Buscar correo..." />
                                        <button class="kt-btn kt-btn-sm kt-btn-outline" type="submit"><i class="ki-filled ki-magnifier"></i></button>
                                    </form>
                                    <span class="kt-badge kt-badge-outline">{{ $accounts->total() }}</span>
                                </div>
                            </div>

                            <div class="border-t border-border">
                                @foreach ($accounts as $account)
                                    @php
                                        $statusClass = str_contains($account->status, 'error')
                                            ? 'kt-badge-danger'
                                            : (in_array($account->status, ['provisioning', 'staged'], true) ? 'kt-badge-warning' : 'kt-badge-success');
                                        $statusLabel = match ($account->status) {
                                            'active' => 'Activa',
                                            'staged' => 'Preparada',
                                            'provisioning' => 'Configurando',
                                            'error' => 'Error',
                                            default => ucfirst($account->status),
                                        };
                                    @endphp
                                    <div class="flex flex-col gap-4 border-b border-border px-5 py-4 last:border-b-0 lg:flex-row lg:items-center lg:justify-between">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full border border-border bg-primary/10 text-primary">
                                                <i class="ki-filled ki-sms text-lg"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <div class="truncate font-semibold text-mono">{{ $account->email }}</div>
                                                <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-secondary-foreground">
                                                    <span>{{ $account->domain->domain }}</span>
                                                    <span class="text-muted-foreground">·</span>
                                                    <span>{{ number_format($account->quota_mb / 1024, 1) }} GB de cuota</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2 lg:ms-auto">
                                            <span class="kt-badge kt-badge-outline {{ $statusClass }} text-xs">{{ $statusLabel }}</span>
                                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted px-3 py-1.5 text-xs font-medium text-secondary-foreground">
                                                <i class="ki-filled ki-send text-sm"></i>
                                                SMTP
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted px-3 py-1.5 text-xs font-medium text-secondary-foreground">
                                                <i class="ki-filled ki-cloud text-sm"></i>
                                                IMAP
                                            </span>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-2">
                                            <button type="button" class="kt-btn kt-btn-outline kt-btn-sm" onclick="document.getElementById('mail-dns-{{ $account->domain->id }}').showModal()">
                                                <i class="ki-filled ki-router"></i>
                                                DNS del correo
                                            </button>
                                            @if ($canManage)
                                                @if ($xmailEnabled)
                                                    <a href="{{ route('xmail.login', ['email' => $account->email]) }}" target="_blank" rel="noopener" class="kt-btn kt-btn-outline kt-btn-sm" title="XMail solicitará la contraseña de {{ $account->email }}">
                                                        <i class="ki-filled ki-sms"></i>
                                                        XMail
                                                    </a>
                                                @endif
                                                @if ($roundcubeEnabled)
                                                    <a href="{{ $webmailUrl }}" target="_blank" rel="noopener" class="kt-btn kt-btn-outline kt-btn-sm" title="Inicia sesión como {{ $account->email }}">
                                                        <i class="ki-filled ki-exit-right-corner"></i>
                                                        Webmail
                                                    </a>
                                                @endif
                                                <details class="relative inline-block text-left">
                                                    <summary class="kt-btn kt-btn-outline kt-btn-sm cursor-pointer list-none">
                                                        <i class="ki-filled ki-key-square"></i>
                                                        Contraseña
                                                    </summary>
                                                    <div class="absolute end-0 z-20 mt-2 w-72 rounded-xl border border-border bg-background p-4 shadow-2xl">
                                                        <form action="{{ route('mail.reset-password', $account) }}" method="POST" class="grid gap-2.5">
                                                            @csrf
                                                            <label class="text-xs font-medium text-mono">Nueva contraseña</label>
                                                            <input type="password" name="password" required minlength="12" autocomplete="new-password"
                                                                   class="kt-input kt-input-sm" placeholder="Mínimo 12 caracteres">
                                                            <button class="kt-btn kt-btn-sm kt-btn-primary" type="submit">Actualizar contraseña</button>
                                                        </form>
                                                    </div>
                                                </details>
                                                <form action="{{ route('mail.destroy', $account) }}" method="POST" class="inline" onsubmit="return confirm('Eliminar {{ $account->email }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="kt-btn kt-btn-icon kt-btn-sm kt-btn-outline" title="Eliminar cuenta">
                                                        <i class="ki-filled ki-trash text-xs"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @if ($accounts->hasPages())
                                <div class="px-5 py-4 border-t border-border">
                                    {{ $accounts->links() }}
                                </div>
                            @endif
                        </div>
                    @endif

                </div>
            </div>
        </main>

        @include('layouts.partials.client.footer')
    </div>
</div>

@foreach ($mailDomains as $mailDomain)
    @php
        $dnsDomain = $mailDomain['domain'];
        $records = $mailDomain['records'];
        $recordRows = array_values(array_filter([
            $records['mx'],
            $records['spf'],
            $records['dkim']['value'] ? $records['dkim'] : null,
            $records['dmarc'],
        ]));
    @endphp
    <dialog id="mail-dns-{{ $dnsDomain->id }}" class="m-auto w-[min(920px,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-2xl border border-border bg-background p-0 text-foreground shadow-2xl backdrop:bg-black/50">
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border bg-background px-6 py-4">
            <div>
                <h2 class="text-lg font-semibold text-mono">DNS del correo · {{ $dnsDomain->domain }}</h2>
                <p class="mt-0.5 text-xs text-secondary-foreground">Configura estos registros en Cloudflare o en el proveedor DNS del dominio.</p>
            </div>
            <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost" onclick="this.closest('dialog').close()" title="Cerrar"><i class="ki-filled ki-cross"></i></button>
        </div>

        <div class="grid gap-6 p-6">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7" data-dns-summary="{{ $dnsDomain->id }}">
                @foreach (['mx' => 'MX', 'a' => 'A', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC', 'ptr' => 'PTR', 'ssl' => 'SSL'] as $key => $label)
                    <div class="rounded-lg border border-border bg-muted/30 px-3 py-2 text-center">
                        <div class="text-xs font-semibold text-mono">{{ $label }}</div>
                        <div class="mt-1 text-xs text-secondary-foreground" data-dns-status="{{ $dnsDomain->id }}:{{ $key }}"><i class="ki-filled ki-time"></i> Sin verificar</div>
                    </div>
                @endforeach
            </div>

            <div class="rounded-xl border border-warning/25 bg-warning/10 px-4 py-3 text-sm text-warning">
                <strong>Cloudflare:</strong> el registro de <code>{{ $records['mail_hostname'] }}</code> debe estar en <strong>DNS only</strong> (nube gris). El proxy naranja no transporta SMTP ni IMAP.
            </div>

            @if ($canManage)
                @php $currentMode = $dnsDomain->mailSettings->outbound_mode ?? 'shared'; @endphp
                <section class="grid gap-3">
                    <div>
                        <h3 class="font-semibold text-mono">Paso 0: modo de envío</h3>
                        <p class="mt-1 text-sm text-secondary-foreground">Por defecto este dominio sale por la IP compartida del servidor. Si tiene una IP dedicada dada de alta arriba, puedes hacer que use su propio PTR/HELO en vez del compartido.</p>
                    </div>
                    <form method="POST" action="{{ route('mail.domains.settings', $dnsDomain) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        @method('PUT')
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label font-normal text-mono text-xs">Modo</label>
                            <select class="kt-select" name="outbound_mode" onchange="this.closest('form').querySelector('[data-dedicated-ip]').classList.toggle('hidden', this.value !== 'dedicated')">
                                <option value="shared" @selected($currentMode === 'shared')>Compartido (servidor)</option>
                                <option value="dedicated" @selected($currentMode === 'dedicated')>IP dedicada</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1 {{ $currentMode === 'dedicated' ? '' : 'hidden' }}" data-dedicated-ip>
                            <label class="kt-form-label font-normal text-mono text-xs">IP dedicada</label>
                            <select class="kt-select" name="server_ip_address_id">
                                @forelse ($serverIpAddresses as $ip)
                                    <option value="{{ $ip->id }}" @selected($dnsDomain->mailSettings?->server_ip_address_id === $ip->id)>{{ $ip->label ?: $ip->ip_address }} ({{ $ip->ip_address }})</option>
                                @empty
                                    <option value="" disabled>No hay IPs dedicadas dadas de alta</option>
                                @endforelse
                            </select>
                        </div>
                        <button type="submit" class="kt-btn kt-btn-outline">Guardar modo</button>
                    </form>
                </section>
            @endif

            <section class="grid gap-3">
                <div>
                    <h3 class="font-semibold text-mono">Paso 1: servidor y recepción</h3>
                    <p class="mt-1 text-sm text-secondary-foreground">El hostname de correo debe apuntar al servidor y el MX del dominio debe dirigir los mensajes hacia él.</p>
                </div>
                @if ($records['mail_host_record'])
                    <div class="overflow-x-auto rounded-xl border border-border">
                        <table class="kt-table w-full min-w-[620px]">
                            <thead><tr><th class="p-3 text-left">Tipo</th><th class="p-3 text-left">Nombre</th><th class="p-3 text-left">Contenido</th><th class="p-3"></th></tr></thead>
                            <tbody>
                                <tr class="border-t border-border"><td class="p-3">A</td><td class="p-3 font-mono">{{ $records['a']['host'] }}</td><td class="p-3 font-mono">{{ $records['a']['value'] ?: 'Configura XPANEL_SERVER_IPV4' }}</td><td class="p-3 text-right"><button type="button" class="kt-btn kt-btn-sm kt-btn-ghost" data-copy="{{ $records['a']['value'] }}">Copiar</button></td></tr>
                                <tr class="border-t border-border"><td class="p-3">MX</td><td class="p-3 font-mono">@</td><td class="p-3 font-mono">{{ $records['mx']['value'] }} <span class="text-secondary-foreground">(prioridad 10)</span></td><td class="p-3 text-right"><button type="button" class="kt-btn kt-btn-sm kt-btn-ghost" data-copy="{{ $records['mx']['value'] }}">Copiar</button></td></tr>
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm text-secondary-foreground">
                        El servidor canónico <strong>{{ $records['mail_hostname'] }}</strong> pertenece a otra zona DNS. Su registro A se configura una sola vez allí. En {{ $dnsDomain->domain }} agrega únicamente el MX hacia ese hostname.
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-border">
                        <table class="kt-table w-full"><tbody><tr><td class="p-3">MX</td><td class="p-3 font-mono">@</td><td class="p-3 font-mono">{{ $records['mx']['value'] }} (10)</td><td class="p-3 text-right"><button type="button" class="kt-btn kt-btn-sm kt-btn-ghost" data-copy="{{ $records['mx']['value'] }}">Copiar</button></td></tr></tbody></table>
                    </div>
                @endif
            </section>

            <section class="grid gap-3">
                <div>
                    <h3 class="font-semibold text-mono">Paso 2: autenticación y reputación</h3>
                    <p class="mt-1 text-sm text-secondary-foreground">Agrega los TXT exactamente como aparecen. DKIM firma realmente los mensajes mediante OpenDKIM.</p>
                </div>
                <div class="overflow-x-auto rounded-xl border border-border">
                    <table class="kt-table w-full min-w-[760px]">
                        <thead><tr><th class="p-3 text-left">Tipo</th><th class="p-3 text-left">Nombre</th><th class="p-3 text-left">Contenido</th><th class="p-3"></th></tr></thead>
                        <tbody>
                            @foreach ($recordRows as $record)
                                <tr class="border-t border-border">
                                    <td class="p-3">{{ $record['type'] }}</td>
                                    <td class="p-3 font-mono">{{ $record['host'] }}</td>
                                    <td class="max-w-xl break-all p-3 font-mono text-xs">{{ $record['value'] }}</td>
                                    <td class="p-3 text-right"><button type="button" class="kt-btn kt-btn-sm kt-btn-ghost" data-copy="{{ $record['value'] }}">Copiar</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (! $records['dkim']['value'])
                    <div class="rounded-xl border border-warning/25 bg-warning/10 px-4 py-3 text-sm text-warning">La clave DKIM se generará al sincronizar una cuenta de este dominio. Si Host fue instalado antes de incluir OpenDKIM, actualiza el código y vuelve a ejecutar <code>sudo bash install.sh</code> una vez.</div>
                @endif
            </section>

            <section class="grid gap-3">
                <div>
                    <h3 class="font-semibold text-mono">Paso 3: PTR y certificado</h3>
                    <p class="mt-1 text-sm text-secondary-foreground">Solicita al proveedor del VDS que la IP <code>{{ $records['server_ipv4'] ?: 'IP_DEL_SERVIDOR' }}</code> tenga PTR hacia <code>{{ $records['mail_hostname'] }}</code>. El PTR no se configura en Cloudflare.</p>
                </div>
                <div class="rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm text-secondary-foreground">Certificado esperado: <strong>Let's Encrypt para {{ $records['mail_hostname'] }}</strong>. Se instala con <code>sudo bash scripts/enable-webmail-ssl.sh correo@example.com</code>.</div>
            </section>
        </div>

        <div class="sticky bottom-0 flex items-center justify-between gap-3 border-t border-border bg-background px-6 py-4">
            <span class="text-xs text-secondary-foreground" data-dns-result="{{ $dnsDomain->id }}">Los cambios DNS pueden tardar en propagarse.</span>
            <button type="button" class="kt-btn kt-btn-primary" data-verify-dns="{{ route('mail.dns-status', $dnsDomain) }}" data-domain-id="{{ $dnsDomain->id }}"><i class="ki-filled ki-arrows-circle"></i> Verificar registros</button>
        </div>
    </dialog>
@endforeach
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        await navigator.clipboard.writeText(button.dataset.copy || '');
        const label = button.textContent;
        button.textContent = 'Copiado';
        setTimeout(() => button.textContent = label, 1200);
    });
});

document.querySelectorAll('[data-verify-dns]').forEach((button) => {
    button.addEventListener('click', async () => {
        const domainId = button.dataset.domainId;
        const result = document.querySelector(`[data-dns-result="${domainId}"]`);
        button.disabled = true;
        result.textContent = 'Consultando DNS público…';
        try {
            const response = await fetch(button.dataset.verifyDns, {headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error('No se pudo verificar el dominio.');
            const payload = await response.json();
            Object.entries(payload.checks || {}).forEach(([key, check]) => {
                const target = document.querySelector(`[data-dns-status="${domainId}:${key}"]`);
                if (!target) return;
                target.className = `mt-1 text-xs ${check.ok ? 'text-success' : 'text-danger'}`;
                target.innerHTML = `<i class="ki-filled ${check.ok ? 'ki-check-circle' : 'ki-cross-circle'}"></i> ${check.ok ? 'OK' : 'Pendiente'}`;
            });
            const complete = Object.values(payload.checks || {}).every((check) => check.ok);
            result.textContent = complete ? 'Todos los registros están configurados correctamente.' : 'Hay registros pendientes o diferentes a los recomendados.';
        } catch (error) {
            result.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
});
</script>
@endpush
