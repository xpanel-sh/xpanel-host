<?php

namespace App\Services;

class XflowCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function nodes(): array
    {
        return [
            'trigger.manual' => $this->node('trigger', 'Ejecución manual', 'Inicia desde el botón Ejecutar.', 'ki-to-right'),
            'trigger.schedule' => $this->node('trigger', 'Programación', 'Inicia con la frecuencia configurada.', 'ki-calendar-tick'),
            'trigger.event' => $this->node('trigger', 'Evento de XPanel', 'Reacciona a una operación real del panel.', 'ki-abstract-26'),
            'trigger.webhook' => $this->node('trigger', 'Webhook entrante', 'Inicia mediante una URL privada.', 'ki-code'),
            'condition.site_status' => $this->node('condition', 'Estado del sitio', 'Comprueba si el sitio está activo o suspendido.', 'ki-status'),
            'condition.ssl_status' => $this->node('condition', 'Estado SSL', 'Comprueba el certificado registrado.', 'ki-shield-tick'),
            'condition.site_type' => $this->node('condition', 'Tipo de aplicación', 'PHP, Node.js o sitio estático.', 'ki-code'),
            'action.backup' => $this->node('action', 'Crear backup', 'Crea una copia completa y aplica retención.', 'ki-cloud-change'),
            'action.git_deploy' => $this->node('action', 'Desplegar Git', 'Despliega la rama ya conectada al sitio.', 'ki-code'),
            'action.cache_purge' => $this->node('action', 'Limpiar caché', 'Purga las ubicaciones de caché conocidas.', 'ki-broom'),
            'action.site_restart' => $this->node('action', 'Reiniciar aplicación', 'Recarga PHP-FPM, Node.js y el servidor web.', 'ki-arrows-circle'),
            'action.malware_scan' => $this->node('action', 'Analizar malware', 'Ejecuta ClamAV y conserva el informe.', 'ki-shield-search'),
            'action.ssl_retry' => $this->node('action', 'Reintentar SSL', 'Solicita nuevamente el certificado del sitio.', 'ki-shield-tick'),
            'action.notify' => $this->node('action', 'Notificar al equipo', 'Crea una notificación interna para todos.', 'ki-notification-status'),
        ];
    }

    /** @return array<string, string> */
    public function events(): array
    {
        return [
            'site.created' => 'Sitio creado', 'site.updated' => 'Sitio actualizado', 'site.restarted' => 'Sitio reiniciado',
            'backup.completed' => 'Backup completado', 'git.deployed' => 'Despliegue Git completado',
            'cache.purged' => 'Caché limpiada', 'malware.scanned' => 'Análisis de malware completado',
            'ssl.issued' => 'Certificado SSL emitido',
        ];
    }

    /** @return array<string, string> */
    public function schedules(): array
    {
        return ['every_five_minutes' => 'Cada 5 minutos', 'hourly' => 'Cada hora', 'daily' => 'Cada día', 'weekly' => 'Cada semana'];
    }

    /** @return array<string, mixed> */
    private function node(string $type, string $label, string $description, string $icon): array
    {
        return compact('type', 'label', 'description', 'icon');
    }
}
