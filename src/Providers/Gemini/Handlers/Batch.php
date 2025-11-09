<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiBatchJob;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

class Batch
{
    public function __construct(
        protected PendingRequest $client,
        protected Text $textHandler,
        protected Structured $structuredHandler,
    ) {}

    /**
     * Create a batch job with inline requests
     *
     * @param  array<TextRequest|StructuredRequest|array<string, mixed>>  $requests  Array of TextRequest, StructuredRequest objects or request payloads
     */
    public function createInline(string $model, array $requests, ?string $displayName = null): GeminiBatchJob
    {
        // Convert TextRequest and StructuredRequest objects to request payloads
        $requestPayloads = array_map(
            fn (array|\Prism\Prism\Text\Request|\Prism\Prism\Structured\Request $request): array => Arr::whereNotNull([
                'request' => match (true) {
                    $request instanceof TextRequest => $this->convertTextRequestToPayload($request),
                    $request instanceof StructuredRequest => $this->convertStructuredRequestToPayload($request),
                    default => $request,
                },
                // Only include metadata if user explicitly provided a batch key
                'metadata' => match (true) {
                    $request instanceof TextRequest && $request->batchKey() !== null => ['key' => $request->batchKey()],
                    $request instanceof StructuredRequest && $request->batchKey() !== null => ['key' => $request->batchKey()],
                    default => null,
                },
            ]),
            $requests
        );

        $requestBody = [
            'batch' => Arr::whereNotNull([
                'display_name' => $displayName ?? 'Prism Batch '.now()->format('Y-m-d H:i:s'),
                'input_config' => [
                    'requests' => [
                        'requests' => $requestPayloads,
                    ],
                ],
            ]),
        ];

        $response = $this->client->post("models/{$model}:batchGenerateContent", $requestBody);

        return GeminiBatchJob::fromResponse($response->json());
    }

    /**
     * Create a batch job from a JSONL file
     */
    public function createFromFile(string $model, string $fileName, ?string $displayName = null): GeminiBatchJob
    {
        $requestBody = [
            'batch' => Arr::whereNotNull([
                'display_name' => $displayName ?? 'Prism Batch '.now()->format('Y-m-d H:i:s'),
                'input_config' => [
                    'file_name' => $fileName,
                ],
            ]),
        ];

        $response = $this->client->post("models/{$model}:batchGenerateContent", $requestBody);

        return GeminiBatchJob::fromResponse($response->json());
    }

    /**
     * Convert Prism requests to JSONL format
     *
     * For file-based batches, keys are required. User must provide keys via withBatchKey().
     *
     * @param  array<TextRequest|StructuredRequest>  $requests  Array of TextRequest or StructuredRequest objects
     * @return string JSONL content
     *
     * @throws PrismException if any request is missing a batch key
     */
    public function convertRequestsToJsonl(array $requests): string
    {
        $jsonlLines = [];

        foreach ($requests as $index => $request) {
            $payload = match (true) {
                $request instanceof TextRequest => $this->convertTextRequestToPayload($request),
                $request instanceof StructuredRequest => $this->convertStructuredRequestToPayload($request),
                default => throw new PrismException('Invalid request type. Only TextRequest and StructuredRequest are supported.'),
            };

            // For file-based batches, keys are required - throw error if not provided
            $key = $request->batchKey();
            if ($key === null) {
                throw new PrismException("Batch key is required for file-based batches. Request at index {$index} is missing a batch key. Use withBatchKey() to provide one.");
            }

            $line = [
                'key' => $key,
                'request' => $payload,
            ];

            $jsonlLines[] = json_encode($line);
        }

        return implode("\n", $jsonlLines);
    }

    /**
     * Parse batch results from output file or inline responses
     *
     * For file-based batches: Returns array keyed by batch key (keys are required)
     * For inline batches: Returns indexed array in API order (keys are optional)
     *
     * @param  string|array<int, array<string, mixed>>|null  $source  Output file URI or inline responses array
     * @return array<string|int, array<string, mixed>> File-based: keyed array, Inline: indexed array
     */
    public function getBatchResults(string|array|null $source): array
    {
        // Handle inline responses
        if (is_array($source)) {
            return $this->parseInlineResponses($source);
        }

        if ($source === null) {
            throw new PrismException('No results available: batch has neither output file URI nor inline responses');
        }

        // Fetch the JSONL output file
        $response = $this->client->get($source);

        if (! $response->successful()) {
            throw new PrismException('Failed to fetch batch results from output file');
        }

        $jsonlContent = $response->body();
        $lines = explode("\n", trim($jsonlContent));

        $results = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $entry = json_decode($line, true);
            if (! $entry) {
                continue;
            }
            if (! isset($entry['key'])) {
                continue;
            }
            if (! isset($entry['response'])) {
                continue;
            }

            $key = $entry['key'];
            $responseData = $entry['response'];

            // File-based batches return keyed array
            $results[$key] = $this->parseResponseData($responseData);
        }

        return $results;
    }

    /**
     * Get batch job status and details
     */
    public function get(string $batchName): GeminiBatchJob
    {
        $response = $this->client->get($batchName);

        return GeminiBatchJob::fromResponse($response->json());
    }

    /**
     * List batch jobs
     *
     * @return GeminiBatchJob[]
     */
    public function list(int $pageSize = 100): array
    {
        $response = $this->client->get('batches', [
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
        $response = $this->client->post("{$batchName}:cancel");

        return GeminiBatchJob::fromResponse($response->json());
    }

    /**
     * Delete a batch job
     */
    public function delete(string $batchName): bool
    {
        $response = $this->client->delete($batchName);

        return $response->successful();
    }

    /**
     * Parse inline batch responses
     *
     * Returns responses as an indexed array in the order provided by the API.
     * If explicit batch keys were provided, they are included in each result under 'batchKey'.
     *
     * @param  array<int, array<string, mixed>>  $inlineResponses
     * @return array<int, array<string, mixed>>
     */
    protected function parseInlineResponses(array $inlineResponses): array
    {
        $results = [];

        foreach ($inlineResponses as $entry) {
            $responseData = $entry['response'] ?? $entry;
            $parsed = $this->parseResponseData($responseData);

            // Include batch key in result if it was provided
            if (isset($entry['metadata']['key'])) {
                $parsed['batchKey'] = $entry['metadata']['key'];
            }

            $results[] = $parsed;
        }

        return $results;
    }

    /**
     * Parse individual response data from batch output
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function parseResponseData(array $data): array
    {
        // Check if response has an error
        if (isset($data['error'])) {
            return [
                'success' => false,
                'error' => [
                    'code' => $data['error']['code'] ?? 'unknown',
                    'message' => $data['error']['message'] ?? 'unknown',
                ],
            ];
        }

        // Extract the text content
        $text = data_get($data, 'candidates.0.content.parts.0.text', '');

        // Check if this is a structured response (JSON)
        $isStructured = false;
        $structured = null;

        if (! empty($text) && str_starts_with(trim((string) $text), '{')) {
            $decoded = json_decode((string) $text, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $isStructured = true;
                $structured = $decoded;
            }
        }

        // Parse usage data
        $usage = [
            'promptTokens' => data_get($data, 'usageMetadata.promptTokenCount', 0),
            'completionTokens' => data_get($data, 'usageMetadata.candidatesTokenCount', 0),
            'totalTokens' => data_get($data, 'usageMetadata.totalTokenCount', 0),
            'cacheReadInputTokens' => data_get($data, 'usageMetadata.cachedContentTokenCount'),
            'thoughtTokens' => data_get($data, 'usageMetadata.thoughtsTokenCount'),
        ];

        // Parse finish reason
        $finishReason = data_get($data, 'candidates.0.finishReason', 'STOP');

        $result = [
            'success' => true,
            'text' => $text,
            'finishReason' => $finishReason,
            'usage' => $usage,
        ];

        if ($isStructured) {
            $result['structured'] = $structured;
            $result['type'] = 'structured';
        } else {
            $result['type'] = 'text';
        }

        // Include metadata if present
        if (isset($data['id'])) {
            $result['meta'] = [
                'id' => $data['id'],
            ];
        }

        return $result;
    }

    /**
     * Convert a TextRequest to a batch request payload
     *
     * Delegates to the Text handler to build the payload
     *
     * @return array<string, mixed>
     */
    protected function convertTextRequestToPayload(TextRequest $request): array
    {
        return $this->textHandler->buildPayload($request);
    }

    /**
     * Convert a StructuredRequest to a batch request payload
     *
     * Delegates to the Structured handler to build the payload
     *
     * @return array<string, mixed>
     */
    protected function convertStructuredRequestToPayload(StructuredRequest $request): array
    {
        return $this->structuredHandler->buildPayload($request);
    }
}
