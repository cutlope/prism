<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Providers\Gemini\Handlers\Batch;
use Prism\Prism\Providers\Gemini\Handlers\Cache;
use Prism\Prism\Providers\Gemini\Handlers\Embeddings;
use Prism\Prism\Providers\Gemini\Handlers\File;
use Prism\Prism\Providers\Gemini\Handlers\Images;
use Prism\Prism\Providers\Gemini\Handlers\Stream;
use Prism\Prism\Providers\Gemini\Handlers\Structured;
use Prism\Prism\Providers\Gemini\Handlers\Text;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiBatchJob;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiCachedObject;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiFile;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

class Gemini extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function images(ImagesRequest $request): ImagesResponse
    {
        $handler = new Images($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
        );

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make([]),
            503 => throw PrismProviderOverloadedException::make(class_basename($this)),
            default => throw PrismException::providerRequestError($model, $e),
        };
    }

    /**
     * @param  Message[]  $messages
     * @param  array<SystemMessage|string>  $systemPrompts
     */
    public function cache(string $model, array $messages = [], array $systemPrompts = [], ?int $ttl = null): GeminiCachedObject
    {
        if ($messages === [] && $systemPrompts === []) {
            throw new PrismException('At least one message or system prompt must be provided');
        }

        $systemPrompts = array_map(
            fn (\Prism\Prism\ValueObjects\Messages\SystemMessage|string $prompt): SystemMessage => $prompt instanceof SystemMessage ? $prompt : new SystemMessage($prompt),
            $systemPrompts
        );

        $handler = new Cache(
            client: $this->client(
                baseUrl: 'https://generativelanguage.googleapis.com/v1beta'
            ),
            model: $model,
            messages: $messages,
            systemPrompts: $systemPrompts,
            ttl: $ttl
        );

        try {
            return $handler->handle();
        } catch (RequestException $e) {
            $this->handleRequestException($model, $e);
        }
    }

    /**
     * Upload a file to the Gemini Files API
     */
    public function uploadFile(string $filePath, ?string $displayName = null, ?string $mimeType = null): GeminiFile
    {
        $handler = new File(
            $this->client(baseUrl: 'https://generativelanguage.googleapis.com'),
            $this->apiKey
        );

        try {
            return $handler->upload($filePath, $displayName, $mimeType);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('file-upload', $e);
        }
    }

    /**
     * Get file metadata from the Gemini Files API
     */
    public function getFile(string $fileName): GeminiFile
    {
        $handler = new File(
            $this->client(baseUrl: 'https://generativelanguage.googleapis.com'),
            $this->apiKey
        );

        try {
            return $handler->get($fileName);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('file-get', $e);
        }
    }

    /**
     * List all files from the Gemini Files API
     *
     * @return GeminiFile[]
     */
    public function listFiles(int $pageSize = 100): array
    {
        $handler = new File(
            $this->client(baseUrl: 'https://generativelanguage.googleapis.com'),
            $this->apiKey
        );

        try {
            return $handler->list($pageSize);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('file-list', $e);
        }
    }

    /**
     * Delete a file from the Gemini Files API
     */
    public function deleteFile(string $fileName): bool
    {
        $handler = new File(
            $this->client(baseUrl: 'https://generativelanguage.googleapis.com'),
            $this->apiKey
        );

        try {
            return $handler->delete($fileName);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('file-delete', $e);
        }
    }

    /**
     * Create a batch job with inline requests
     *
     * @param  array<TextRequest|StructuredRequest|array<string, mixed>>  $requests  Array of TextRequest, StructuredRequest objects or request payloads
     */
    public function createBatchInline(string $model, array $requests, ?string $displayName = null): GeminiBatchJob
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $handler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        try {
            return $handler->createInline($model, $requests, $displayName);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('batch-create', $e);
        }
    }

    /**
     * Create a batch job from a JSONL file
     *
     * @param  string  $fileName  The file name/ID from Files API (e.g., 'files/abc123')
     */
    public function createBatchFromFile(string $model, string $fileName, ?string $displayName = null): GeminiBatchJob
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $handler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        try {
            return $handler->createFromFile($model, $fileName, $displayName);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('batch-create-file', $e);
        }
    }

    /**
     * Create a batch job from Prism requests (automatically generates and uploads JSONL file)
     *
     * @param  array<\Prism\Prism\Text\Request|\Prism\Prism\Structured\Request>  $requests  Array of TextRequest or StructuredRequest objects
     */
    public function createBatchFromRequests(string $model, array $requests, ?string $displayName = null): GeminiBatchJob
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $batchHandler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        // Convert requests to JSONL format
        $jsonlContent = $batchHandler->convertRequestsToJsonl($requests);

        // Save JSONL to a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'prism_batch_');
        file_put_contents($tempFile, $jsonlContent);

        try {
            // Upload the JSONL file
            $file = $this->uploadFile(
                filePath: $tempFile,
                displayName: $displayName ?? 'Prism Batch Requests '.now()->format('Y-m-d H:i:s'),
                mimeType: 'application/jsonl'
            );

            // Wait for the file to be processed
            $maxAttempts = 30; // 30 seconds max
            $attempts = 0;
            while ($file->isProcessing() && $attempts < $maxAttempts) {
                sleep(1);
                $file = $this->getFile($file->name);
                $attempts++;
            }

            if ($file->isFailed()) {
                throw new PrismException('File upload failed during processing');
            }

            if ($file->isProcessing()) {
                throw new PrismException('File is still processing after 30 seconds');
            }

            // Create the batch from the uploaded file
            return $this->createBatchFromFile($model, $file->name, $displayName);
        } finally {
            // Clean up the temporary file
            @unlink($tempFile);
        }
    }

    /**
     * Convert Prism requests to JSONL format
     *
     * @param  array<\Prism\Prism\Text\Request|\Prism\Prism\Structured\Request>  $requests  Array of TextRequest or StructuredRequest objects
     * @return string JSONL content
     *
     * @throws PrismException if any request is missing a batch key
     */
    public function convertRequestsToJsonl(array $requests): string
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $batchHandler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        return $batchHandler->convertRequestsToJsonl($requests);
    }

    /**
     * Get batch job status and details
     */
    public function getBatch(string $batchName): GeminiBatchJob
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $handler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        try {
            return $handler->get($batchName);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('batch-get', $e);
        }
    }

    /**
     * List all batch jobs
     *
     * @return GeminiBatchJob[]
     */
    public function listBatches(int $pageSize = 100): array
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $handler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        try {
            return $handler->list($pageSize);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('batch-list', $e);
        }
    }

    /**
     * Cancel a batch job
     */
    public function cancelBatch(string $batchName): GeminiBatchJob
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $handler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        try {
            return $handler->cancel($batchName);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('batch-cancel', $e);
        }
    }

    /**
     * Delete a batch job
     */
    public function deleteBatch(string $batchName): bool
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $handler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        try {
            return $handler->delete($batchName);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('batch-delete', $e);
        }
    }

    /**
     * Get and parse batch results from output file, inline responses, or batch job object
     *
     * For file-based batches: Returns an associative array keyed by batch key
     * For inline batches: Returns an indexed array in API order
     *
     * @param  string|array<int, array<string, mixed>>|GeminiBatchJob  $source  Output file URI, inline responses array, or batch job object
     * @return array<string|int, array<string, mixed>> File-based: keyed array, Inline: indexed array
     */
    public function getBatchResults(string|array|GeminiBatchJob $source): array
    {
        $client = $this->client(baseUrl: 'https://generativelanguage.googleapis.com/v1beta');
        $handler = new Batch(
            $client,
            new Handlers\Text($client, $this->apiKey),
            new Handlers\Structured($client)
        );

        // If given a GeminiBatchJob, extract the appropriate source
        if ($source instanceof GeminiBatchJob) {
            $source = $source->inlineResponses ?? $source->outputFileUri;
        }

        try {
            return $handler->getBatchResults($source);
        } catch (RequestException $e) {
            throw PrismException::providerRequestError('batch-results', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
            ])
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}
