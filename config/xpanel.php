<?php

return [
    /*
     * Initial backend for new sites. Nginx is always installed; optional
     * engines only become selectable after installation from Host settings.
     */
    'web_server' => env('XPANEL_WEB_SERVER', 'nginx'),

    /*
     * Host never sells plans. In standalone mode it reports the Linux server
     * resources. In VM mode it reports the MicroVM allocation and offers a
     * link back to VM for infrastructure operations.
     */
    'management_mode' => env('XPANEL_MANAGEMENT_MODE', 'standalone'),
    'instance_id' => env('XPANEL_INSTANCE_ID'),
    'instance_root' => env('XPANEL_INSTANCE_ROOT'),
    'control_plane_url' => env('XPANEL_CONTROL_PLANE_URL'),
    'broker_url' => env('XPANEL_BROKER_URL'),
    'broker_secret' => env('XPANEL_BROKER_SECRET'),
    'web_root' => rtrim(env('XPANEL_WEB_ROOT', '/var/www'), '/'),
    'account_user' => env('XPANEL_ACCOUNT_USER', 'xpa'.substr(hash('sha256', (string) env('APP_KEY', 'xpanel-host')), 0, 10)),
    'account_home' => env('XPANEL_ACCOUNT_HOME', '/home/'.env('XPANEL_ACCOUNT_USER', 'xpa'.substr(hash('sha256', (string) env('APP_KEY', 'xpanel-host')), 0, 10))),
    'vm_url' => env('XPANEL_VM_URL'),
    'vm_service_id' => env('XPANEL_VM_SERVICE_ID'),
    'panel_domain' => env('XPANEL_PANEL_DOMAIN'),
    'panel_access_mode' => env('XPANEL_PANEL_ACCESS_MODE', 'ip'),
    'panel_port' => (int) env('XPANEL_PANEL_PORT', 80),
    'assigned_cpu' => env('XPANEL_ASSIGNED_CPU'),
    'assigned_cpu_percent' => env('XPANEL_ASSIGNED_CPU_PERCENT'),
    'assigned_memory_mib' => env('XPANEL_ASSIGNED_MEMORY_MIB'),
    'assigned_disk_gib' => env('XPANEL_ASSIGNED_DISK_GIB'),
    'assigned_storage_mib' => env('XPANEL_ASSIGNED_STORAGE_MIB'),
    'assigned_inodes' => env('XPANEL_ASSIGNED_INODES'),
    'assigned_bandwidth_gb' => env('XPANEL_ASSIGNED_BANDWIDTH_GB'),
    'assigned_max_sites' => env('XPANEL_ASSIGNED_MAX_SITES'),
    'systemd_slice' => env('XPANEL_SYSTEMD_SLICE'),
    'cgroup_root' => env('XPANEL_CGROUP_ROOT', '/sys/fs/cgroup'),

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
    'mail_root' => env('XPANEL_MAIL_ROOT', rtrim(env('XPANEL_ACCOUNT_HOME', '/home/'.env('XPANEL_ACCOUNT_USER', 'xpa'.substr(hash('sha256', (string) env('APP_KEY', 'xpanel-host')), 0, 10))), '/').'/mail'),
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
    'node_versions' => array_values(array_filter(array_map('trim', explode(',', env('XPANEL_NODE_VERSIONS', '22'))))),

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
