<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="es">

<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'xpanel-host')</title>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1, shrink-to-fit=no" name="viewport" />
    <meta name="robots" content="noindex, nofollow, noarchive" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet" />
    <link href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/css/styles.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/css/xpanel.css') }}" rel="stylesheet" />
    @stack('styles')
</head>

@php
    $clientSites = auth()->check() ? \App\Models\Site::whereNull('parent_site_id')->orderBy('domain')->get(['id', 'domain', 'status']) : collect();
    $routeSite = request()->route('site');
    $selectedSite = $routeSite instanceof \App\Models\Site ? $routeSite : null;
    $selectedPrimarySite = $selectedSite?->parent_site_id ? $selectedSite->parent : $selectedSite;
    $selectedSubdomain = $selectedSite?->parent_site_id ? $selectedSite : null;
    $clientSubdomains = auth()->check()
        ? \App\Models\Site::with('parent:id,domain')
            ->whereNotNull('parent_site_id')
            ->when($selectedPrimarySite, fn ($query) => $query->where('parent_site_id', $selectedPrimarySite->id))
            ->orderBy('domain')
            ->get(['id', 'parent_site_id', 'domain', 'status'])
        : collect();
    $selectedSiteDomain = $selectedSite?->domain;
    $hasSecondarySidebar = $selectedSiteDomain !== null;
@endphp
<body class="antialiased flex h-full text-base text-foreground bg-background bg-muted! lg:overflow-hidden" style="--header-height:60px; --sidebar-width:{{ $hasSecondarySidebar ? '310px' : '90px' }}; --navbar-height:56px">
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

    <div class="flex grow">
        <header class="flex items-center fixed z-10 top-0 left-0 right-0 shrink-0 h-(--header-height) bg-muted xpanel-header">
            <div class="kt-container-fluid flex justify-between items-stretch px-5 lg:ps-0 lg:gap-4">
                <div class="flex items-center me-1">
                    <div class="flex items-center justify-center gap-1 shrink-0">
                        <button class="kt-btn kt-btn-icon kt-btn-ghost -ms-2 lg:hidden" data-kt-drawer-toggle="#sidebar">
                            <i class="ki-filled ki-menu"></i>
                        </button>
                    </div>
                    <div class="flex min-w-0 items-center gap-2 @if($selectedSiteDomain) mx-5 @endif">
                        <h3 class="text-secondary-foreground text-base hidden md:block">xpanel-host</h3>
                        <span class="text-sm text-muted-foreground font-medium px-2.5 hidden md:inline">/</span>
                        <div class="kt-menu kt-menu-default" data-kt-menu="true">
                            <div class="kt-menu-item kt-menu-item-dropdown" data-kt-menu-item-offset="0, 10px"
                                data-kt-menu-item-placement="bottom-start" data-kt-menu-item-toggle="dropdown"
                                data-kt-menu-item-trigger="hover">
                                <button class="kt-menu-toggle text-mono font-medium" style="max-width:11rem" data-header-site-toggle>
                                    <span class="truncate">{{ $selectedPrimarySite?->domain ?? 'Sitios' }}</span>
                                    <span class="kt-menu-arrow"><i class="ki-filled ki-down"></i></span>
                                </button>
                                <div class="kt-menu-dropdown w-64 py-2">
                                    @forelse ($clientSites as $clientSite)
                                        <div class="kt-menu-item {{ $selectedPrimarySite?->is($clientSite) ? 'active' : '' }}">
                                            <a class="kt-menu-link" href="{{ route('sites.show', $clientSite) }}">
                                                <span class="kt-menu-icon"><i class="ki-filled ki-click"></i></span>
                                                <span class="kt-menu-title">{{ $clientSite->domain }}</span>
                                            </a>
                                        </div>
                                    @empty
                                        <div class="px-3 py-2 text-xs text-secondary-foreground">Aun no tienes sitios creados.</div>
                                    @endforelse
                                    <div class="kt-menu-separator"></div>
                                    <div class="kt-menu-item">
                                        <a class="kt-menu-link" href="{{ route('sites.create') }}">
                                            <span class="kt-menu-icon"><i class="ki-filled ki-plus"></i></span>
                                            <span class="kt-menu-title">Crear sitio</span>
                                        </a>
                                    </div>
                                    <div class="kt-menu-separator"></div>
                                    <label class="flex cursor-pointer items-center justify-between gap-4 px-3 py-2 text-sm" data-header-subdomains-control>
                                        <span class="flex min-w-0 items-center gap-2"><i class="ki-filled ki-router text-secondary-foreground"></i><span class="truncate">Mostrar subdominios</span></span>
                                        <input class="kt-switch" id="header_subdomains_toggle" type="checkbox" aria-controls="header_subdomain_selector">
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="hidden items-center gap-2" id="header_subdomain_selector" data-header-subdomain-selector>
                            <span class="hidden text-muted-foreground md:inline">/</span>
                            <div class="kt-menu kt-menu-default" data-kt-menu="true">
                                <div class="kt-menu-item kt-menu-item-dropdown" data-kt-menu-item-offset="0, 10px"
                                    data-kt-menu-item-placement="bottom-start" data-kt-menu-item-toggle="dropdown"
                                    data-kt-menu-item-trigger="hover">
                                    <button class="kt-menu-toggle text-mono font-medium" style="max-width:12rem" data-header-subdomain-toggle>
                                        <span class="truncate">{{ $selectedSubdomain?->domain ?? 'Subdominios' }}</span>
                                        <span class="kt-menu-arrow"><i class="ki-filled ki-down"></i></span>
                                    </button>
                                    <div class="kt-menu-dropdown w-64 py-2">
                                        <div class="px-3 pb-2 text-xs font-medium uppercase tracking-wide text-secondary-foreground">
                                            {{ $selectedPrimarySite ? 'Subdominios de '.$selectedPrimarySite->domain : 'Todos los subdominios' }}
                                        </div>
                                        @forelse ($clientSubdomains as $clientSubdomain)
                                            <div class="kt-menu-item {{ $selectedSubdomain?->is($clientSubdomain) ? 'active' : '' }}">
                                                <a class="kt-menu-link" href="{{ route('sites.show', $clientSubdomain) }}">
                                                    <span class="kt-menu-icon"><i class="ki-filled ki-router"></i></span>
                                                    <span class="kt-menu-title min-w-0">
                                                        <span class="block truncate">{{ $clientSubdomain->domain }}</span>
                                                        @unless($selectedPrimarySite)<span class="block truncate text-xs text-secondary-foreground">{{ $clientSubdomain->parent?->domain }}</span>@endunless
                                                    </span>
                                                </a>
                                            </div>
                                        @empty
                                            <div class="px-3 py-2 text-xs text-secondary-foreground">{{ $selectedPrimarySite ? 'Este sitio aún no tiene subdominios.' : 'Aún no tienes subdominios.' }}</div>
                                        @endforelse
                                        @if ($selectedPrimarySite)
                                            <div class="kt-menu-separator"></div>
                                            <div class="kt-menu-item">
                                                <a class="kt-menu-link" href="{{ route('sites.subdomains.index', $selectedPrimarySite) }}">
                                                    <span class="kt-menu-icon"><i class="ki-filled ki-plus"></i></span>
                                                    <span class="kt-menu-title">Gestionar subdominios</span>
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center lg:gap-3.5">
                    <div class="flex items-center gap-1.5">
                        <button class="group kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:[&_i]:text-primary"
                            data-kt-modal-toggle="#search_modal">
                            <i class="ki-filled ki-magnifier text-lg group-hover:text-primary"></i>
                        </button>

                        <button class="relative kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:[&_i]:text-primary"
                            data-kt-drawer-toggle="#chat_drawer" data-team-chat-open aria-label="Abrir chat del equipo">
                            <i class="ki-filled ki-messages text-lg"></i>
                            <span class="hidden absolute -end-1 -top-1 min-w-4 h-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white" id="team_chat_badge"></span>
                        </button>
                        <div class="hidden kt-drawer kt-drawer-end card flex-col max-w-[90%] w-[400px] top-5 bottom-5 end-5 rounded-xl border border-border"
                            data-kt-drawer="true" data-kt-drawer-container="body" id="chat_drawer">
                            <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-3.5 border-b border-b-border">
                                <div class="flex min-w-0 items-center gap-2"><button class="hidden kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" id="team_chat_back" type="button"><i class="ki-filled ki-left"></i></button><div class="min-w-0"><div class="truncate" id="team_chat_title">Chat del equipo</div><div class="mt-0.5 truncate text-xs font-normal text-secondary-foreground" id="team_chat_subtitle">Conversaciones internas de esta instalación</div></div></div>
                                <div class="flex items-center gap-2"><button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" id="team_chat_new" type="button" aria-label="Nueva conversación"><i class="ki-filled ki-plus"></i></button><button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true" data-team-chat-close><i class="ki-filled ki-cross"></i></button></div>
                            </div>
                            <div class="kt-scrollable-y-auto grow" id="team_chat_messages"><div class="p-10 text-center text-sm text-secondary-foreground">Cargando conversaciones…</div></div>
                            <form class="hidden grid gap-3 border-t border-border p-4" id="team_chat_form">
                                <textarea class="kt-textarea min-h-20 resize-none" id="team_chat_body" maxlength="2000" placeholder="Escribe un mensaje para el equipo…" required></textarea>
                                <div class="flex items-center justify-between gap-3"><span class="text-xs text-secondary-foreground" id="team_chat_status">Enter para enviar · Shift+Enter para una línea nueva</span><button class="kt-btn kt-btn-primary" type="submit"><i class="ki-filled ki-send"></i> Enviar</button></div>
                            </form>
                        </div>

                        <button class="relative kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:[&_i]:text-primary"
                            data-kt-drawer-toggle="#notifications_drawer" data-notifications-open aria-label="Abrir notificaciones">
                            <i class="ki-filled ki-notification-status text-lg"></i>
                            <span class="hidden absolute -end-1 -top-1 min-w-4 h-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white" id="notifications_badge"></span>
                        </button>
                        <div class="hidden kt-drawer kt-drawer-end card flex-col max-w-[90%] w-[400px] top-5 bottom-5 end-5 rounded-xl border border-border"
                            data-kt-drawer="true" data-kt-drawer-container="body" id="notifications_drawer">
                            <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-3.5 border-b border-b-border">
                                <span>Notificaciones</span>
                                <div class="flex items-center gap-2"><button class="kt-btn kt-btn-sm kt-btn-ghost" id="notifications_read_all" type="button">Marcar leídas</button><button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true"><i class="ki-filled ki-cross"></i></button></div>
                            </div>
                            <div class="kt-scrollable-y-auto grow divide-y divide-border" id="notifications_list"><div class="p-10 text-center text-sm text-secondary-foreground">Cargando notificaciones…</div></div>
                        </div>
                    </div>

                    <div data-kt-dropdown="true" data-kt-dropdown-offset="10px, 10px" data-kt-dropdown-placement="bottom-end">
                        <div class="cursor-pointer shrink-0 kt-btn kt-btn-icon kt-btn-ghost rounded-full size-9 bg-accent/60" data-kt-dropdown-toggle="true">
                            <i class="ki-filled ki-user text-lg"></i>
                        </div>
                        <div class="kt-dropdown-menu w-[220px]" data-kt-dropdown-menu="true">
                            <div class="flex items-center gap-2 px-2.5 py-2">
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-sm text-foreground font-semibold leading-none">{{ auth()->user()?->name }}</span>
                                    <span class="text-xs text-secondary-foreground font-medium leading-none">{{ auth()->user()?->email }}</span>
                                </div>
                            </div>
                            <ul class="kt-dropdown-menu-sub">
                                <li><div class="kt-dropdown-menu-separator"></div></li>
                            </ul>
                            <div class="px-2.5 pt-1.5 mb-2.5 flex flex-col gap-3.5">
                                <div class="flex items-center gap-2 justify-between">
                                    <span class="flex items-center gap-2">
                                        <i class="ki-filled ki-moon text-base text-muted-foreground"></i>
                                        <span class="font-medium text-2sm">Modo oscuro</span>
                                    </span>
                                    <input class="kt-switch" data-kt-theme-switch-state="dark" data-kt-theme-switch-toggle="true" type="checkbox" value="1" />
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="kt-btn kt-btn-outline justify-center w-full">Salir</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex flex-col lg:flex-row grow pt-(--header-height) lg:pt-0">
            @include('layouts.partials.client.sidebar')

            @yield('content')
        </div>
    </div>

    <div class="kt-modal" data-kt-modal="true" id="search_modal">
        <div class="kt-modal-content max-w-[600px] top-[15%]">
            <div class="kt-modal-header py-4 px-5">
                <i class="ki-filled ki-magnifier text-muted-foreground text-xl"></i>
                <input class="kt-input kt-input-ghost" placeholder="Buscar sitios, dominios..." type="text" />
                <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-modal-dismiss="true">
                    <i class="ki-filled ki-cross"></i>
                </button>
            </div>
            <div class="kt-modal-body p-5 pt-0 text-sm text-secondary-foreground text-center">
                Empieza a escribir para buscar.
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/js/core.bundle.js') }}"></script>
    <script src="{{ asset('assets/vendors/ktui/ktui.min.js') }}"></script>
    <script>
        (() => {
            const toggle = document.getElementById('header_subdomains_toggle');
            const selector = document.querySelector('[data-header-subdomain-selector]');
            const control = document.querySelector('[data-header-subdomains-control]');
            if (!toggle || !selector) return;

            const storageKey = 'xpanel-header-show-subdomains';
            const render = (persist = false) => {
                selector.classList.toggle('hidden', !toggle.checked);
                selector.classList.toggle('flex', toggle.checked);
                if (persist) localStorage.setItem(storageKey, toggle.checked ? '1' : '0');
            };

            toggle.checked = localStorage.getItem(storageKey) === '1';
            render();
            toggle.addEventListener('change', () => render(true));
            control?.addEventListener('click', event => event.stopPropagation());
        })();
    </script>
    <script>
        (() => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const chat = {
                conversations: @json(route('team-chat.conversations')),
                create: @json(route('team-chat.conversations.store')),
                messages: @json(route('team-chat.messages', '__conversation__')),
                store: @json(route('team-chat.messages.store', '__conversation__')),
                read: @json(route('team-chat.read', '__conversation__')),
            };
            const notifications = {
                index: @json(route('notifications.index')),
                read: @json(route('notifications.read', '__notification__')),
                readAll: @json(route('notifications.read-all')),
            };
            const requestJson = async (url, options = {}) => {
                const response = await fetch(url, {
                    ...options,
                    headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers ?? {})},
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message ?? 'No se pudo completar la operación.');
                return payload;
            };
            const time = value => value ? new Intl.DateTimeFormat('es', {dateStyle: 'short', timeStyle: 'short'}).format(new Date(value)) : '';
            const setBadge = (element, count) => {
                if (!element) return;
                element.textContent = count > 99 ? '99+' : String(count);
                element.classList.toggle('hidden', count < 1);
                element.classList.toggle('flex', count > 0);
            };

            const chatList = document.getElementById('team_chat_messages');
            const chatBadge = document.getElementById('team_chat_badge');
            const chatForm = document.getElementById('team_chat_form');
            const chatBody = document.getElementById('team_chat_body');
            const chatStatus = document.getElementById('team_chat_status');
            const chatTitle = document.getElementById('team_chat_title');
            const chatSubtitle = document.getElementById('team_chat_subtitle');
            const chatBack = document.getElementById('team_chat_back');
            const chatNew = document.getElementById('team_chat_new');
            let activeConversation = null;
            let chatView = 'list';
            let teamUsers = [];
            let conversationCache = [];
            const conversationUrl = (template, id) => template.replace('__conversation__', encodeURIComponent(id));
            const setChatHeader = (title, subtitle, inside = false) => {
                chatTitle.textContent = title;
                chatSubtitle.textContent = subtitle;
                chatBack.classList.toggle('hidden', !inside);
                chatNew.classList.toggle('hidden', inside);
                chatForm.classList.toggle('hidden', !activeConversation);
            };
            const renderMessages = messages => {
                chatList.replaceChildren();
                const viewport = document.createElement('div');
                viewport.className = 'p-5';
                if (messages.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'flex h-full flex-col items-center justify-center gap-2 py-10 text-center text-sm text-secondary-foreground';
                    empty.textContent = 'Aún no hay mensajes en esta conversación.';
                    viewport.append(empty);
                    chatList.append(viewport);
                    return;
                }
                const stack = document.createElement('div');
                stack.className = 'grid gap-3';
                messages.forEach(message => {
                    const row = document.createElement('div');
                    row.className = `flex ${message.mine ? 'justify-end' : 'justify-start'}`;
                    const bubble = document.createElement('div');
                    bubble.className = `max-w-[85%] rounded-xl px-3 py-2 ${message.mine ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground'}`;
                    const meta = document.createElement('div');
                    meta.className = `mb-1 text-xs font-medium ${message.mine ? 'text-primary-foreground' : 'text-secondary-foreground'}`;
                    meta.textContent = `${message.sender} · ${time(message.created_at)}`;
                    const body = document.createElement('div');
                    body.className = 'whitespace-pre-wrap break-words text-sm';
                    body.textContent = message.body;
                    bubble.append(meta, body);
                    row.append(bubble);
                    stack.append(row);
                });
                viewport.append(stack);
                chatList.append(viewport);
                chatList.scrollTop = chatList.scrollHeight;
            };
            const renderConversationList = () => {
                activeConversation = null;
                chatView = 'list';
                setChatHeader('Chat del equipo', 'Directos y grupos internos');
                chatList.replaceChildren();
                if (conversationCache.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'p-10 text-center text-sm text-secondary-foreground';
                    empty.textContent = 'No tienes conversaciones. Crea una con el botón +.';
                    chatList.append(empty);
                    return;
                }
                conversationCache.forEach(conversation => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'flex w-full items-center gap-3 border-b border-border p-4 text-start hover:bg-muted';
                    const icon = document.createElement('span');
                    icon.className = 'flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary';
                    const iconGlyph = document.createElement('i');
                    iconGlyph.className = `ki-filled ${conversation.type === 'direct' ? 'ki-user' : 'ki-people'}`;
                    icon.append(iconGlyph);
                    const content = document.createElement('span');
                    content.className = 'min-w-0 grow';
                    const name = document.createElement('span');
                    name.className = 'block truncate text-sm font-medium text-mono';
                    name.textContent = conversation.name;
                    const preview = document.createElement('span');
                    preview.className = 'mt-1 block truncate text-xs text-secondary-foreground';
                    preview.textContent = conversation.latest_message ? `${conversation.latest_sender ?? ''}: ${conversation.latest_message}` : conversation.participants.join(', ');
                    content.append(name, preview);
                    button.append(icon, content);
                    if (conversation.unread > 0) {
                        const unread = document.createElement('span');
                        unread.className = 'flex min-w-5 h-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground';
                        unread.textContent = conversation.unread > 99 ? '99+' : String(conversation.unread);
                        button.append(unread);
                    }
                    button.addEventListener('click', () => openConversation(conversation));
                    chatList.append(button);
                });
            };
            const refreshConversations = async (render = activeConversation === null) => {
                try {
                    const payload = await requestJson(chat.conversations);
                    conversationCache = payload.conversations ?? [];
                    teamUsers = payload.users ?? [];
                    setBadge(chatBadge, Number(payload.unread ?? 0));
                    if (render) renderConversationList();
                } catch (error) {
                    chatList.replaceChildren();
                    const failure = document.createElement('div');
                    failure.className = 'p-6 text-center text-sm text-danger';
                    failure.textContent = error.message;
                    chatList.append(failure);
                }
            };
            const refreshMessages = async () => {
                if (!activeConversation) return;
                try {
                    const payload = await requestJson(conversationUrl(chat.messages, activeConversation.id));
                    activeConversation = payload.conversation;
                    setChatHeader(activeConversation.name, activeConversation.participants.join(', '), true);
                    renderMessages(payload.messages ?? []);
                    if (Number(payload.unread ?? 0) > 0) await markChatRead();
                } catch (error) {
                    chatStatus.textContent = error.message;
                }
            };
            const markChatRead = async () => {
                if (!activeConversation) return;
                try {
                    await requestJson(conversationUrl(chat.read, activeConversation.id), {method: 'POST'});
                    await refreshConversations(false);
                } catch (_) {}
            };
            const openConversation = async conversation => {
                activeConversation = conversation;
                chatView = 'conversation';
                setChatHeader(conversation.name, conversation.participants.join(', '), true);
                chatForm.classList.remove('hidden');
                chatList.innerHTML = '<div class="p-10 text-center text-sm text-secondary-foreground">Cargando mensajes…</div>';
                await refreshMessages();
                setTimeout(() => chatBody?.focus(), 100);
            };
            const renderCreateConversation = () => {
                activeConversation = null;
                chatView = 'create';
                setChatHeader('Nueva conversación', 'Directa o grupo interno', true);
                chatForm.classList.add('hidden');
                chatList.replaceChildren();
                const form = document.createElement('form');
                form.className = 'grid gap-4 p-5';
                const type = document.createElement('select');
                type.className = 'kt-select';
                type.innerHTML = '<option value="direct">Mensaje directo</option><option value="group">Crear grupo</option>';
                const name = document.createElement('input');
                name.className = 'hidden kt-input';
                name.placeholder = 'Nombre del grupo';
                name.maxLength = 80;
                const users = document.createElement('select');
                users.className = 'kt-select min-h-40';
                users.multiple = false;
                users.size = 6;
                teamUsers.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = `${user.name} · ${user.email}`;
                    users.append(option);
                });
                const help = document.createElement('p');
                help.className = 'text-xs text-secondary-foreground';
                help.textContent = 'Para un mensaje directo selecciona una persona. Para un grupo puedes seleccionar varias.';
                const submit = document.createElement('button');
                submit.className = 'kt-btn kt-btn-primary w-fit';
                submit.type = 'submit';
                submit.textContent = 'Crear conversación';
                const status = document.createElement('p');
                status.className = 'text-sm text-danger';
                type.addEventListener('change', () => {
                    const group = type.value === 'group';
                    name.classList.toggle('hidden', !group);
                    users.multiple = group;
                    Array.from(users.options).forEach(option => { option.selected = false; });
                });
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    const participantIds = Array.from(users.selectedOptions).map(option => Number(option.value));
                    submit.disabled = true;
                    status.textContent = '';
                    try {
                        const payload = await requestJson(chat.create, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({type: type.value, name: name.value, participant_ids: participantIds})});
                        await refreshConversations(false);
                        await openConversation(payload.conversation);
                    } catch (error) {
                        status.textContent = error.message;
                    } finally {
                        submit.disabled = false;
                    }
                });
                form.append(type, name, users, help, submit, status);
                chatList.append(form);
            };
            document.querySelector('[data-team-chat-open]')?.addEventListener('click', () => {
                refreshConversations(chatView === 'list');
            });
            chatBack?.addEventListener('click', renderConversationList);
            chatNew?.addEventListener('click', renderCreateConversation);
            chatForm?.addEventListener('submit', async event => {
                event.preventDefault();
                if (!activeConversation) return;
                const body = chatBody.value.trim();
                if (!body) return;
                const button = chatForm.querySelector('button[type="submit"]');
                button.disabled = true;
                chatStatus.textContent = 'Enviando…';
                try {
                    await requestJson(conversationUrl(chat.store, activeConversation.id), {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({body})});
                    chatBody.value = '';
                    await refreshMessages();
                    await markChatRead();
                    chatStatus.textContent = 'Mensaje enviado';
                } catch (error) {
                    chatStatus.textContent = error.message;
                } finally {
                    button.disabled = false;
                }
            });
            chatBody?.addEventListener('keydown', event => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    chatForm?.requestSubmit();
                }
            });

            const notificationList = document.getElementById('notifications_list');
            const notificationBadge = document.getElementById('notifications_badge');
            const renderNotifications = items => {
                notificationList.replaceChildren();
                if (items.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'flex h-full flex-col items-center justify-center gap-2 p-10 text-center text-sm text-secondary-foreground';
                    empty.textContent = 'No tienes notificaciones.';
                    notificationList.append(empty);
                    return;
                }
                items.forEach(item => {
                    const link = document.createElement('a');
                    link.href = item.url || '/';
                    link.className = `flex gap-3 p-4 hover:bg-muted ${item.read ? '' : 'bg-primary/5'}`;
                    const iconBox = document.createElement('span');
                    iconBox.className = `flex size-9 shrink-0 items-center justify-center rounded-lg ${item.level === 'danger' ? 'bg-danger/10 text-danger' : item.level === 'warning' ? 'bg-warning/10 text-warning' : 'bg-primary/10 text-primary'}`;
                    const icon = document.createElement('i');
                    icon.className = `ki-filled ${item.icon || 'ki-notification-status'}`;
                    iconBox.append(icon);
                    const content = document.createElement('span');
                    content.className = 'min-w-0 grow';
                    const title = document.createElement('span');
                    title.className = 'block text-sm font-medium text-mono';
                    title.textContent = item.title;
                    const message = document.createElement('span');
                    message.className = 'mt-1 block text-xs text-secondary-foreground';
                    message.textContent = item.message || '';
                    const date = document.createElement('span');
                    date.className = 'mt-1 block text-xs text-muted-foreground';
                    date.textContent = time(item.created_at);
                    content.append(title, message, date);
                    link.append(iconBox, content);
                    if (!item.read) link.addEventListener('click', async event => {
                        event.preventDefault();
                        try { await requestJson(notifications.read.replace('__notification__', encodeURIComponent(item.id)), {method: 'POST'}); } catch (_) {}
                        window.location.href = link.href;
                    });
                    notificationList.append(link);
                });
            };
            const refreshNotifications = async () => {
                try {
                    const payload = await requestJson(notifications.index);
                    renderNotifications(payload.notifications ?? []);
                    setBadge(notificationBadge, Number(payload.unread ?? 0));
                } catch (error) {
                    notificationList.replaceChildren();
                    const failure = document.createElement('div');
                    failure.className = 'p-6 text-center text-sm text-danger';
                    failure.textContent = error.message;
                    notificationList.append(failure);
                }
            };
            document.querySelector('[data-notifications-open]')?.addEventListener('click', refreshNotifications);
            document.getElementById('notifications_read_all')?.addEventListener('click', async () => {
                try {
                    await requestJson(notifications.readAll, {method: 'POST'});
                    await refreshNotifications();
                } catch (_) {}
            });

            refreshConversations();
            refreshNotifications();
            setInterval(async () => { if (!document.hidden) { await refreshConversations(chatView === 'list'); if (chatView === 'conversation') await refreshMessages(); refreshNotifications(); } }, 15000);
        })();
    </script>
    @stack('scripts')
</body>

</html>
