<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Providers\Gemini\Handlers\Audio;
use Prism\Prism\Providers\Gemini\Handlers\Cache;
use Prism\Prism\Providers\Gemini\Handlers\Embeddings;
use Prism\Prism\Providers\Gemini\Handlers\Images;
use Prism\Prism\Providers\Gemini\Handlers\Stream;
use Prism\Prism\Providers\Gemini\Handlers\Structured;
use Prism\Prism\Providers\Gemini\Handlers\Text;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiCachedObject;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\ProviderRateLimit;
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
    public function textToSpeech(TextToSpeechRequest $request): TextToSpeechResponse
    {
        $handler = new Audio($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handleTextToSpeech($request);
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
        $data = $e->response->json() ?? [];

        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($data),
                retryAfter: $this->parseRetryAfter($e->response, $data),
            ),
            503 => throw PrismProviderOverloadedException::make(class_basename($this)),
            default => $this->handleResponseErrors($e),
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

    protected function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json() ?? [];

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Gemini',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.status'),
            errorMessage: data_get($data, 'error.message'),
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ProviderRateLimit[]
     */
    protected function processRateLimits(array $data): array
    {
        $details = data_get($data, 'error.details', []);

        if (! is_array($details)) {
            return [];
        }

        $rateLimits = [];

        foreach ($details as $detail) {
            if (data_get($detail, '@type') !== 'type.googleapis.com/google.rpc.QuotaFailure') {
                continue;
            }

            $violations = data_get($detail, 'violations', []);

            if (! is_array($violations)) {
                continue;
            }

            foreach ($violations as $violation) {
                $metric = (string) data_get($violation, 'quotaMetric', '');
                $name = $metric !== '' ? Str::afterLast($metric, '/') : 'quota';

                $quotaValue = data_get($violation, 'quotaValue');

                $rateLimits[] = new ProviderRateLimit(
                    name: $name,
                    limit: is_numeric($quotaValue) ? (int) $quotaValue : null
                );
            }
        }

        return $rateLimits;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function parseRetryAfter(Response $response, array $data): ?int
    {
        $headerRetryAfter = $response->header('retry-after');
        if ($headerRetryAfter !== null && is_numeric($headerRetryAfter)) {
            return (int) $headerRetryAfter;
        }

        $details = data_get($data, 'error.details', []);

        if (is_array($details)) {
            foreach ($details as $detail) {
                if (data_get($detail, '@type') !== 'type.googleapis.com/google.rpc.RetryInfo') {
                    continue;
                }

                $retryDelay = data_get($detail, 'retryDelay');
                if (is_string($retryDelay)) {
                    $parsedRetryDelay = $this->parseDurationToSeconds($retryDelay);

                    if ($parsedRetryDelay !== null && $parsedRetryDelay > 0) {
                        return $parsedRetryDelay;
                    }
                }
            }
        }

        $message = data_get($data, 'error.message');
        if (is_string($message) && preg_match('/Please retry in ([0-9.]+)(ms|s)\.?/i', $message, $matches) === 1) {
            return $this->parseDurationToSeconds($matches[1].$matches[2]);
        }

        return null;
    }

    protected function parseDurationToSeconds(string $duration): ?int
    {
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)(ms|s)$/i', trim($duration), $matches) !== 1) {
            return null;
        }

        $value = (float) $matches[1];
        $unit = strtolower($matches[2]);

        if ($unit === 'ms') {
            return (int) ceil($value / 1000);
        }

        return (int) ceil($value);
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
