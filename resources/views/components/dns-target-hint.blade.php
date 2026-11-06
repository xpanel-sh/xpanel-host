@php($dnsServerIp = (string) config('xpanel.server_ipv4'))
<div {{ $attributes->class(['rounded-xl border border-primary/20 bg-primary/5 px-4 py-3']) }}>
    <div class="flex items-start gap-3">
        <i class="ki-filled ki-information-2 mt-0.5 text-primary"></i>
        <div class="min-w-0 text-sm">
            <div class="font-medium text-mono">Apunta el dominio al servidor</div>
            @if($dnsServerIp !== '')
                <p class="mt-1 text-secondary-foreground">Crea un registro <strong>A</strong> hacia <strong class="font-mono text-mono">{{ $dnsServerIp }}</strong>. Si usas Cloudflare, mantenlo en <strong>Solo DNS</strong> durante la verificación y emisión SSL.</p>
            @else
                <p class="mt-1 text-warning">La IP pública no fue detectada. Revísala en Ajustes antes de validar DNS o emitir SSL.</p>
            @endif
        </div>
    </div>
</div>
