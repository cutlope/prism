<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiBatchJob;

class Batch
{
    public function __construct(
        protected PendingRequest $client,
    ) {}

    /**
     * Create a batch job with inline requests
     *
     * @param  array<array<string, mixed>>  $requests  Array of request payloads
     */
    public function createInline(string $model, array $requests): GeminiBatchJob
    {
        $response = $this->client->post('/batches', [
            'requests' => $requests,
        ]);

        return GeminiBatchJob::fromResponse($response->json());
    }

    /**
     * Create a batch job from a JSONL file
     */
    public function createFromFile(string $inputFileUri): GeminiBatchJob
    {
        $response = $this->client->post('/batches', [
            'sourceUri' => $inputFileUri,
        ]);

        return GeminiBatchJob::fromResponse($response->json());
    }

    /**
     * Get batch job status and details
     */
    public function get(string $batchName): GeminiBatchJob
    {
        $response = $this->client->get("/{$batchName}");

        return GeminiBatchJob::fromResponse($response->json());
    }

    /**
     * List batch jobs
     *
     * @return GeminiBatchJob[]
     */
    public function list(int $pageSize = 100): array
    {
        $response = $this->client->get('/batches', [
            'pageSize' => $pageSize,
        ]);

        $batches = data_get($response->json(), 'batchJobs', []);

        return array_map(
            GeminiBatchJob::fromResponse(...),
            $batches
        );
    }

    /**
     * Cancel a batch job
     */
    public function cancel(string $batchName): GeminiBatchJob
    {
        $response = $this->client->post("/{$batchName}:cancel");

        return GeminiBatchJob::fromResponse($response->json());
    }

    /**
     * Delete a batch job
     */
    public function delete(string $batchName): bool
    {
        $response = $this->client->delete("/{$batchName}");

        return $response->successful();
    }

    /**
     * Build a request payload for batch API
     *
     * @param  array<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $systemInstruction
     * @param  array<string, mixed>  $generationConfig
     * @return array<string, mixed>
     */
    public function buildRequest(
        string $model,
        array $messages = [],
        ?array $systemInstruction = null,
        array $generationConfig = [],
        ?string $cachedContentName = null,
        array $tools = [],
        ?array $toolConfig = null,
        ?array $safetySettings = null
    ): array {
        return Arr::whereNotNull([
            'model' => 'models/'.$model,
            'contents' => $messages,
            'systemInstruction' => $systemInstruction,
            'cachedContent' => $cachedContentName,
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
            'tools' => $tools !== [] ? $tools : null,
            'tool_config' => $toolConfig,
            'safetySettings' => $safetySettings,
        ]);
    }
}
