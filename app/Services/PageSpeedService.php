<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PageSpeedService
{
    /** @return array{performance_score: int, categories: array<string, int|null>, metrics: array<string, array{title: string, value: string, milliseconds: float|null}>, opportunities: array<int, array{title: string, value: string}>} */
    public function analyze(Site $site, string $strategy): array
    {
        $url = ($site->ssl_status === 'active' ? 'https://' : 'http://').$site->domain.'/';
        $query = 'url='.rawurlencode($url).'&strategy='.rawurlencode($strategy)
            .'&category=PERFORMANCE&category=ACCESSIBILITY&category=BEST_PRACTICES&category=SEO&locale=es';
        $key = config('services.pagespeed.key');
        if (is_string($key) && $key !== '') {
            $query .= '&key='.rawurlencode($key);
        }
        $response = Http::acceptJson()->timeout(120)->retry(2, 1000)->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?'.$query);
        if (! $response->successful()) {
            $message = $response->json('error.message');
            throw new RuntimeException('PageSpeed no pudo analizar el sitio'.(is_string($message) ? ': '.$message : '.'));
        }
        $lighthouse = $response->json('lighthouseResult');
        if (! is_array($lighthouse)) {
            throw new RuntimeException('PageSpeed devolvió una respuesta sin resultados Lighthouse.');
        }
        $categories = [];
        foreach (['performance', 'accessibility', 'best-practices', 'seo'] as $category) {
            $score = data_get($lighthouse, "categories.{$category}.score");
            $categories[$category] = is_numeric($score) ? (int) round((float) $score * 100) : null;
        }
        if ($categories['performance'] === null) {
            throw new RuntimeException('PageSpeed no devolvió una puntuación de rendimiento.');
        }
        $metrics = [];
        foreach ([
            'first-contentful-paint' => 'FCP', 'largest-contentful-paint' => 'LCP',
            'total-blocking-time' => 'TBT', 'cumulative-layout-shift' => 'CLS', 'speed-index' => 'Speed Index',
        ] as $auditKey => $label) {
            $audit = data_get($lighthouse, 'audits.'.$auditKey);
            if (is_array($audit)) {
                $metrics[$auditKey] = [
                    'title' => $label, 'value' => (string) ($audit['displayValue'] ?? 'Sin dato'),
                    'milliseconds' => isset($audit['numericValue']) && is_numeric($audit['numericValue']) ? round((float) $audit['numericValue'], 2) : null,
                ];
            }
        }
        $opportunities = [];
        foreach ((array) ($lighthouse['audits'] ?? []) as $audit) {
            if (! is_array($audit) || data_get($audit, 'details.type') !== 'opportunity' || ! isset($audit['title'])) {
                continue;
            }
            $opportunities[] = ['title' => (string) $audit['title'], 'value' => (string) ($audit['displayValue'] ?? 'Revisar')];
            if (count($opportunities) === 8) {
                break;
            }
        }

        return ['performance_score' => $categories['performance'], 'categories' => $categories, 'metrics' => $metrics, 'opportunities' => $opportunities];
    }
}
