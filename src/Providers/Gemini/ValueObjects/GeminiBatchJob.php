<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\ValueObjects;

use Illuminate\Support\Carbon;

readonly class GeminiBatchJob
{
    public function __construct(
        public string $name,
        public string $model,
        public string $state,
        public Carbon $createTime,
        public Carbon $updateTime,
        public ?string $outputFileUri = null,
        public ?array $inlineResponses = null,
        public ?array $metadata = null,
        public ?array $error = null,
    ) {}

    /**
     * @param  array<string,mixed>  $response
     */
    public static function fromResponse(array $response): self
    {
        // Gemini returns batch data inside 'metadata' for getBatch, but at top level for createBatch
        $data = $response['metadata'] ?? $response;

        $inlineResponses = data_get($data, 'output.inlinedResponses.inlinedResponses');

        return new self(
            name: data_get($response, 'name', '') ?: data_get($data, 'name', ''),
            model: data_get($data, 'model', ''),
            state: data_get($data, 'state', ''),
            createTime: Carbon::parse(data_get($data, 'createTime')),
            updateTime: Carbon::parse(data_get($data, 'updateTime')),
            outputFileUri: $inlineResponses ? null : data_get($data, 'outputUri'),
            inlineResponses: $inlineResponses,
            metadata: data_get($response, 'metadata'),
            error: data_get($response, 'error') ?: data_get($data, 'error'),
        );
    }

    public function isCompleted(): bool
    {
        return in_array($this->state, ['JOB_STATE_SUCCEEDED', 'BATCH_STATE_SUCCEEDED'], true);
    }

    public function isFailed(): bool
    {
        return in_array($this->state, ['JOB_STATE_FAILED', 'BATCH_STATE_FAILED'], true);
    }

    public function isPending(): bool
    {
        return in_array($this->state, ['JOB_STATE_PENDING', 'BATCH_STATE_PENDING'], true);
    }

    public function isRunning(): bool
    {
        return in_array($this->state, ['JOB_STATE_RUNNING', 'BATCH_STATE_RUNNING'], true);
    }

    public function isCancelled(): bool
    {
        return in_array($this->state, ['JOB_STATE_CANCELLED', 'BATCH_STATE_CANCELLED'], true);
    }

    public function isExpired(): bool
    {
        return in_array($this->state, ['JOB_STATE_EXPIRED', 'BATCH_STATE_EXPIRED'], true);
    }
}
