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
    'acme_email' => env('XPANEL_ACME_EMAIL'),
    'mail_hostname' => env('XPANEL_MAIL_HOSTNAME'),
    'webmail_hostname' => env('XPANEL_WEBMAIL_HOSTNAME', env('XPANEL_MAIL_HOSTNAME')),
    'webmail_url' => env('XPANEL_WEBMAIL_URL'),
    'roundcube_enabled' => env('XPANEL_ROUNDCUBE_ENABLED', true),
    'xmail_enabled' => env('XPANEL_XMAIL_ENABLED', false),
    'mail_uid' => (int) env('XPANEL_MAIL_UID', 5000),
    'mail_gid' => (int) env('XPANEL_MAIL_GID', 5000),
    'server_ipv4' => env('XPANEL_SERVER_IPV4'),
    'dkim_selector' => env('XPANEL_DKIM_SELECTOR', 'xpanel'),
    'php_versions' => array_values(array_filter(array_map('trim', explode(',', env('XPANEL_PHP_VERSIONS', '8.1,8.2,8.3,8.4'))))),
];
