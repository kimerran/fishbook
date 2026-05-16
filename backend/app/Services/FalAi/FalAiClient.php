<?php

namespace App\Services\FalAi;

use App\Exceptions\FalAi\FalAiFailedException;
use App\Exceptions\FalAi\FalAiQuotaException;
use App\Exceptions\FalAi\FalAiTimeoutException;
use App\Services\Backgrounds\BackgroundImageProcessor;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class FalAiClient
{
    public function __construct(
        private readonly Http $http,
        private readonly Repository $config,
        private readonly BackgroundImageProcessor $processor,
    ) {}

    /** @return array{storage_key:string,width:int,height:int} */
    public function generateBackground(string $prompt, string $aspectRatio, int $userId): array
    {
        $base = rtrim((string) $this->config->get('services.fal.base_url'), '/');
        $model = (string) $this->config->get('services.fal.model');
        $apiKey = (string) $this->config->get('services.fal.api_key');
        $maxS = (int) $this->config->get('services.fal.poll_max_seconds', 60);
        $intMs = (int) $this->config->get('services.fal.poll_interval_ms', 1000);

        Log::info('falai.submit', ['user_id' => $userId, 'prompt' => $prompt, 'aspect_ratio' => $aspectRatio]);

        $client = $this->http
            ->withHeaders(['Authorization' => 'Key '.$apiKey, 'accept' => 'application/json'])
            ->connectTimeout(5)
            ->timeout(60)
            ->retry(2, 250, throw: false);

        $submit = $client->post("$base/$model", [
            'prompt' => $prompt,
            'image_size' => $this->imageSizeFor($aspectRatio),
            'num_images' => 1,
        ]);
        if ($submit->status() === 429) {
            throw new FalAiQuotaException('Fal AI quota exhausted.');
        }
        if (! $submit->successful()) {
            throw new FalAiFailedException('Fal AI submit failed: '.$submit->status());
        }

        $statusUrl = (string) $submit->json('status_url');
        $responseUrl = (string) $submit->json('response_url');

        $deadline = microtime(true) + $maxS;
        $completed = false;
        while (microtime(true) < $deadline) {
            $poll = $client->get($statusUrl);
            if (! $poll->successful()) {
                throw new FalAiFailedException('Fal AI poll failed: '.$poll->status());
            }
            $status = $poll->json('status');
            if ($status === 'COMPLETED') {
                $completed = true;
                break;
            }
            if ($status === 'FAILED') {
                throw new FalAiFailedException('Fal AI reported FAILED.');
            }
            usleep($intMs * 1000);
        }
        if (! $completed) {
            throw new FalAiTimeoutException('Fal AI did not complete within 60s.');
        }

        $result = $client->get($responseUrl);
        $url = (string) $result->json('images.0.url');
        if ($url === '') {
            throw new FalAiFailedException('Fal AI returned no image URL.');
        }

        $imageBytes = $client->get($url)->body();
        $tmp = tempnam(sys_get_temp_dir(), 'fal-');
        file_put_contents($tmp, $imageBytes);
        $upload = new UploadedFile($tmp, 'generated.png', 'image/png', null, true);

        return $this->processor->process($upload, $userId);
    }

    private function imageSizeFor(string $aspect): string
    {
        return match ($aspect) {
            '3:2' => 'landscape_3_2',
            '1:1' => 'square_hd',
            default => 'landscape_16_9',
        };
    }
}
