<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiBatchJob;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\ProviderTool;

class Batch
{
    public function __construct(
        protected PendingRequest $client,
    ) {}

    /**
     * Create a batch job with inline requests
     *
     * @param  array<TextRequest|array<string, mixed>>  $requests  Array of TextRequest objects or request payloads
     */
    public function createInline(string $model, array $requests): GeminiBatchJob
    {
        // Convert TextRequest objects to request payloads
        $requestPayloads = array_map(
            fn (array|\Prism\Prism\Text\Request $request): array => $request instanceof TextRequest
                ? $this->convertTextRequestToPayload($request)
                : $request,
            $requests
        );

        $response = $this->client->post('/batches', [
            'requests' => $requestPayloads,
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
     * Convert a TextRequest to a batch request payload
     *
     * @return array<string, mixed>
     */
    protected function convertTextRequestToPayload(TextRequest $request): array
    {
        $providerOptions = $request->providerOptions();

        $thinkingConfig = Arr::whereNotNull([
            'thinkingBudget' => $providerOptions['thinkingBudget'] ?? null,
        ]);

        $generationConfig = Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'topP' => $request->topP(),
            'maxOutputTokens' => $request->maxTokens(),
            'thinkingConfig' => $thinkingConfig !== [] ? $thinkingConfig : null,
        ]);

        if ($request->tools() !== [] && $request->providerTools() !== []) {
            throw new PrismException('Use of provider tools with custom tools is not currently supported by Gemini.');
        }

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = [
                Arr::mapWithKeys(
                    $request->providerTools(),
                    fn (ProviderTool $providerTool): array => [$providerTool->type => (object) []]
                ),
            ];
        }

        if ($request->tools() !== []) {
            $tools['function_declarations'] = ToolMap::map($request->tools());
        }

        return Arr::whereNotNull([
            'model' => 'models/'.$request->model(),
            ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
            'cachedContent' => $providerOptions['cachedContentName'] ?? null,
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
            'tools' => $tools !== [] ? $tools : null,
            'tool_config' => $request->toolChoice() ? ToolChoiceMap::map($request->toolChoice()) : null,
            'safetySettings' => $providerOptions['safetySettings'] ?? null,
        ]);
    }
}
