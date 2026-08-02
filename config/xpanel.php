<?php

return [
    /*
     * Initial backend for new sites. Nginx is always installed; optional
     * engines only become selectable after installation from Host settings.
     */
    'web_server' => env('XPANEL_WEB_SERVER', 'nginx'),

    /*
     * Host never sells plans. In standalone mode it reports the Linux server
     * resources. In core mode it reports the MicroVM allocation and offers a
     * link back to Core for infrastructure operations.
     */
    'management_mode' => env('XPANEL_MANAGEMENT_MODE', 'standalone'),
    'core_url' => env('XPANEL_CORE_URL'),
    'core_service_id' => env('XPANEL_CORE_SERVICE_ID'),
    'panel_domain' => env('XPANEL_PANEL_DOMAIN'),
    'panel_access_mode' => env('XPANEL_PANEL_ACCESS_MODE', 'ip'),
    'panel_port' => (int) env('XPANEL_PANEL_PORT', 80),
    'assigned_cpu' => env('XPANEL_ASSIGNED_CPU'),
    'assigned_memory_mib' => env('XPANEL_ASSIGNED_MEMORY_MIB'),
    'assigned_disk_gib' => env('XPANEL_ASSIGNED_DISK_GIB'),

    /*
     * System changes are disabled for local development and tests. The Linux
     * installer enables them after installing the narrow sudo helper used to
     * apply staged nginx/Apache and PHP-FPM configuration.
     */
    'apply_system_changes' => env('XPANEL_APPLY_SYSTEM_CHANGES', false),
    'site_helper' => env('XPANEL_SITE_HELPER', base_path('scripts/xpanel-site-helper.sh')),
    'site_user' => env('XPANEL_SITE_USER', 'www-data'),
    'site_group' => env('XPANEL_SITE_GROUP', 'www-data'),
    'backup_root' => env('XPANEL_BACKUP_ROOT'),
    'acme_email' => env('XPANEL_ACME_EMAIL'),
    'mail_hostname' => env('XPANEL_MAIL_HOSTNAME'),
    'webmail_hostname' => env('XPANEL_WEBMAIL_HOSTNAME', env('XPANEL_MAIL_HOSTNAME')),
    'webmail_url' => env('XPANEL_WEBMAIL_URL'),
    'roundcube_enabled' => env('XPANEL_ROUNDCUBE_ENABLED', true),
    'xmail_enabled' => env('XPANEL_XMAIL_ENABLED', true),
    'xmail_attachment_max_bytes' => (int) env('XPANEL_XMAIL_ATTACHMENT_MAX_BYTES', 52428800),
    'phpmyadmin_enabled' => env('XPANEL_PHPMYADMIN_ENABLED', true),
    'mail_uid' => (int) env('XPANEL_MAIL_UID', 5000),
    'mail_gid' => (int) env('XPANEL_MAIL_GID', 5000),
    'server_ipv4' => env('XPANEL_SERVER_IPV4'),
    'dkim_selector' => env('XPANEL_DKIM_SELECTOR', 'xpanel'),
    'php_versions' => array_values(array_filter(array_map('trim', explode(',', env('XPANEL_PHP_VERSIONS', '8.1,8.2,8.3,8.4'))))),

    /*
     * Real per-site terminal: a loopback-only Go agent bridges a browser
     * WebSocket to a real SSH session (see agent/README.md). Laravel issues
     * opaque, single-use capabilities; the agent holds no signing secret and
     * sshd forces final consumption/identity verification before the shell.
     */
    'terminal_enabled' => env('XPANEL_TERMINAL_ENABLED', false),
    'terminal_agent_host' => env('XPANEL_TERMINAL_AGENT_HOST', '127.0.0.1'),
    'terminal_agent_port' => (int) env('XPANEL_TERMINAL_AGENT_PORT', 7092),
    'terminal_service_user' => env('XPANEL_TERMINAL_SERVICE_USER', 'xpanel-terminal'),
];
