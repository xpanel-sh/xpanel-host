<?php

namespace App\Services;

use App\Models\AiConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiProviderClient
{
    public function reply(AiConnection $connection, string $context, array $messages): string
    {
        return match ($connection->provider) {
            'openai' => $this->openAi($connection, $context, $messages),
            'anthropic' => $this->anthropic($connection, $context, $messages),
            default => throw new RuntimeException('Proveedor de IA no compatible.'),
        };
    }

    private function openAi(AiConnection $connection, string $context, array $messages): string
    {
        $response = $this->http()->withToken($connection->api_key)->post('https://api.openai.com/v1/responses', [
            'model' => $connection->model,
            'instructions' => $this->instructions($context),
            'input' => collect($messages)->map(fn (array $message): array => [
                'role' => $message['role'],
                'content' => $message['content'],
            ])->values()->all(),
        ]);
        $this->assertSuccessful($response->successful(), $response->json('error.message'));

        $parts = collect($response->json('output', []))
            ->flatMap(fn (array $item): array => $item['content'] ?? [])
            ->where('type', 'output_text')
            ->pluck('text');

        return $this->nonEmpty($parts->implode("\n"));
    }

    private function anthropic(AiConnection $connection, string $context, array $messages): string
    {
        $response = $this->http()->withHeaders([
            'x-api-key' => $connection->api_key,
            'anthropic-version' => '2023-06-01',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => $connection->model,
            'max_tokens' => 2048,
            'system' => $this->instructions($context),
            'messages' => $messages,
        ]);
        $this->assertSuccessful($response->successful(), $response->json('error.message'));

        return $this->nonEmpty(collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()->connectTimeout(10)->timeout(120);
    }

    private function instructions(string $context): string
    {
        return "Eres un agente de programación integrado en Ikode de XPanel Host. Responde en español, con precisión y sin afirmar que modificaste archivos. Sólo conoces el ámbito entregado por XPanel; no solicites ni reveles secretos.\n\n{$context}";
    }

    private function assertSuccessful(bool $successful, mixed $message): void
    {
        if (! $successful) {
            throw new RuntimeException(is_string($message) && $message !== '' ? $message : 'El proveedor de IA rechazó la solicitud.');
        }
    }

    private function nonEmpty(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('El proveedor no devolvió texto.');
        }

        return $text;
    }
}
