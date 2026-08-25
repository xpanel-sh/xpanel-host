<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\MailAccount;
use App\Models\Site;
use App\Models\SiteDatabase;
use App\Models\User;
use App\Support\Permissions;
use App\Support\SiteModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GlobalSearchController extends Controller
{
    private const LIMIT = 40;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'site' => ['nullable', 'string', 'max:253'],
        ]);
        $query = trim(str_replace(['%', '_'], '', (string) ($data['q'] ?? '')));

        if (mb_strlen($query) < 2) {
            return response()->json(['query' => $query, 'results' => [], 'count' => 0]);
        }

        $user = $request->user();
        $results = collect();

        if ($user->hasPermission(Permissions::SITES_VIEW)) {
            $this->addNavigation($results, $query, $user->hasPermission(Permissions::SITES_MANAGE));
            $this->addSites($results, $query);
            $this->addDomains($results, $query);
            $this->addMailAccounts($results, $query);
            $this->addDatabases($results, $query);
            $this->addModules($results, $query, $data['site'] ?? null);
        }

        if ($user->hasPermission(Permissions::TEAM_MANAGE)) {
            $this->addTeam($results, $query);
        }

        if ($user->hasPermission(Permissions::SERVER_MANAGE)) {
            $this->addServerPages($results, $query);
        }

        $results = $results
            ->sortBy(fn (array $result): string => sprintf('%02d-%02d-%s', $result['priority'], $this->relevance($result, $query), Str::lower($result['title'])))
            ->take(self::LIMIT)
            ->values()
            ->map(fn (array $result): array => collect($result)->except('priority')->all());

        return response()->json(['query' => $query, 'results' => $results, 'count' => $results->count()]);
    }

    private function addSites(Collection $results, string $query): void
    {
        Site::query()->with('parent:id,domain')->where('domain', 'like', '%'.$query.'%')->orderBy('domain')->limit(12)->get()
            ->each(function (Site $site) use ($results): void {
                $subdomain = $site->parent_site_id !== null;
                $results->push($this->result(
                    $subdomain ? 'Subdominios' : 'Sitios',
                    $site->domain,
                    $subdomain ? 'Subdominio de '.$site->parent?->domain : 'Sitio web · '.$site->status,
                    route('sites.show', $site),
                    $subdomain ? 'ki-abstract-26' : 'ki-screen',
                    $subdomain ? 11 : 10,
                ));
            });
    }

    private function addDomains(Collection $results, string $query): void
    {
        Domain::query()->with('site:id,domain')->where('domain', 'like', '%'.$query.'%')->orderBy('domain')->limit(8)->get()
            ->each(fn (Domain $domain) => $results->push($this->result(
                'Dominios',
                $domain->domain,
                $domain->site ? 'Conectado a '.$domain->site->domain : 'Dominio sin sitio conectado',
                route('domains.index', ['search' => $domain->domain]),
                'ki-click',
                20,
            )));
    }

    private function addMailAccounts(Collection $results, string $query): void
    {
        MailAccount::query()->with('domain:id,domain')
            ->where(function ($builder) use ($query): void {
                if (str_contains($query, '@')) {
                    [$localPart, $domain] = explode('@', $query, 2);
                    $builder->where('local_part', 'like', '%'.$localPart.'%')
                        ->whereHas('domain', fn ($domainQuery) => $domainQuery->where('domain', 'like', '%'.$domain.'%'));

                    return;
                }
                $builder->where('local_part', 'like', '%'.$query.'%')
                    ->orWhereHas('domain', fn ($domainQuery) => $domainQuery->where('domain', 'like', '%'.$query.'%'));
            })
            ->orderBy('local_part')->limit(8)->get()
            ->each(fn (MailAccount $account) => $results->push($this->result(
                'Correos', $account->email, 'Buzón · '.$account->status,
                route('mail.index', ['search' => $account->email]), 'ki-sms', 30,
            )));
    }

    private function addDatabases(Collection $results, string $query): void
    {
        SiteDatabase::query()->with('site:id,domain')
            ->where(fn ($builder) => $builder->where('name', 'like', '%'.$query.'%')->orWhere('username', 'like', '%'.$query.'%'))
            ->orderBy('name')->limit(8)->get()
            ->each(fn (SiteDatabase $database) => $results->push($this->result(
                'Bases de datos', $database->name,
                $database->username.' · '.$database->site?->domain,
                route('sites.databases.index', $database->site), 'ki-data', 40,
            )));
    }

    private function addModules(Collection $results, string $query, ?string $currentDomain): void
    {
        $modules = collect(SiteModules::catalog())->flatMap(function (array $section, string $sectionKey): Collection {
            return collect($section['items'])->map(fn (array $item, string $key): array => $item + [
                'key' => $key, 'section_key' => $sectionKey, 'section' => $section['label'],
            ]);
        })->filter(function (array $module) use ($query): bool {
            return SiteModules::isReady($module['section_key'], $module['key'])
                && $this->matches($query, $module['label'], $module['description'], $module['section'], $module['key']);
        })->take(6);

        if ($modules->isEmpty()) {
            return;
        }

        $sites = Site::query()->whereNull('parent_site_id')
            ->when($currentDomain, fn ($builder) => $builder->orderByRaw('CASE WHEN domain = ? THEN 0 ELSE 1 END', [$currentDomain]))
            ->orderBy('domain')->limit(4)->get();

        foreach ($modules as $module) {
            foreach ($sites as $site) {
                $results->push($this->result(
                    'Módulos', $module['label'], $site->domain.' · '.$module['section'],
                    SiteModules::url($site, $module['section_key'], $module['key']), $module['icon'], 60,
                ));
            }
        }
    }

    private function addNavigation(Collection $results, string $query, bool $canManage): void
    {
        $pages = [
            ['Inicio', 'Resumen, métricas y actividad del servidor', url('/'), 'ki-home', ['dashboard', 'inicio']],
            ['Sitios web', 'Todos los sitios y subdominios', route('sites.index'), 'ki-screen', ['websites', 'paginas']],
            ['Dominios', 'Portafolio y estado DNS', route('domains.index'), 'ki-click', ['dns']],
            ['Correos', 'Buzones, webmail y entrega', route('mail.index'), 'ki-sms', ['email', 'mail']],
            ['Gestor de archivos', 'Archivos de todos los sitios', route('sites.ikode'), 'ki-folder', ['ikode', 'archivos']],
            ['XFlow', 'Builder visual de workflows y automatizaciones', route('xflow.index'), 'ki-abstract-26', ['workflow', 'flujos', 'automatizacion']],
        ];
        if ($canManage) {
            $pages = array_merge($pages, [
                ['Crear sitio', 'Añadir un nuevo sitio web', route('sites.create'), 'ki-plus', ['nuevo website']],
                ['Conectar dominio', 'Añadir un dominio al servidor', route('domains.create'), 'ki-plus', ['nuevo dominio']],
                ['Crear correo', 'Crear una cuenta de correo', route('mail.create'), 'ki-plus', ['nuevo email', 'buzon']],
            ]);
        }
        foreach ($pages as [$title, $subtitle, $url, $icon, $keywords]) {
            if ($this->matches($query, $title, $subtitle, ...$keywords)) {
                $action = $canManage && Str::startsWith($title, ['Crear', 'Conectar']);
                $results->push($this->result($action ? 'Acciones' : 'Páginas', $title, $subtitle, $url, $icon, $action ? 0 : 5));
            }
        }
    }

    private function addTeam(Collection $results, string $query): void
    {
        foreach ([
            ['Equipo', 'Usuarios y colaboradores del servidor', route('team.index'), 'ki-people', ['usuarios']],
            ['Invitar usuario', 'Crear una cuenta para un colaborador', route('team.create'), 'ki-plus', ['nuevo usuario']],
            ['Roles y permisos', 'Control de acceso del equipo', route('roles.index'), 'ki-security-user', ['permisos']],
        ] as [$title, $subtitle, $url, $icon, $keywords]) {
            if ($this->matches($query, $title, $subtitle, ...$keywords)) {
                $action = Str::contains($title, 'Invitar');
                $results->push($this->result($action ? 'Acciones' : 'Páginas', $title, $subtitle, $url, $icon, $action ? 0 : 5));
            }
        }

        User::query()->with('role:id,name')->where(fn ($builder) => $builder->where('name', 'like', '%'.$query.'%')->orWhere('email', 'like', '%'.$query.'%'))
            ->orderBy('name')->limit(8)->get()
            ->each(fn (User $member) => $results->push($this->result(
                'Equipo', $member->name, $member->email.' · '.($member->role?->name ?? 'Sin rol'),
                route('team.edit', $member), 'ki-user', 50,
            )));
    }

    private function addServerPages(Collection $results, string $query): void
    {
        foreach ([
            ['Acceso al panel', 'Dominio, IP y SSL administrativo', route('settings.panel-access.index'), 'ki-setting-2', ['ajustes', 'configuracion']],
            ['Servidores web', 'Nginx, Apache y OpenLiteSpeed', route('settings.web-servers.index'), 'ki-technology-2', ['motores', 'software']],
        ] as [$title, $subtitle, $url, $icon, $keywords]) {
            if ($this->matches($query, $title, $subtitle, ...$keywords)) {
                $results->push($this->result('Ajustes', $title, $subtitle, $url, $icon, 6));
            }
        }
    }

    private function matches(string $query, string ...$values): bool
    {
        return Str::contains(Str::lower(implode(' ', $values)), Str::lower($query));
    }

    private function relevance(array $result, string $query): int
    {
        $title = Str::lower($result['title']);
        $query = Str::lower($query);

        return $title === $query ? 0 : (Str::startsWith($title, $query) ? 1 : 2);
    }

    private function result(string $group, string $title, string $subtitle, string $url, string $icon, int $priority): array
    {
        return compact('group', 'title', 'subtitle', 'url', 'icon', 'priority');
    }
}
