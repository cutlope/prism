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
        public ?array $metadata = null,
        public ?array $error = null,
    ) {}

    /**
     * @param  array<string,mixed>  $response
     */
    public static function fromResponse(array $response): self
    {
        return new self(
            name: data_get($response, 'name', ''),
            model: data_get($response, 'model', ''),
            state: data_get($response, 'state', ''),
            createTime: Carbon::parse(data_get($response, 'createTime')),
            updateTime: Carbon::parse(data_get($response, 'updateTime')),
            outputFileUri: data_get($response, 'outputUri'),
            metadata: data_get($response, 'metadata'),
            error: data_get($response, 'error'),
        );
    }

    public function isCompleted(): bool
    {
        return $this->state === 'JOB_STATE_SUCCEEDED';
    }

    public function isFailed(): bool
    {
        return $this->state === 'JOB_STATE_FAILED';
    }

    public function isPending(): bool
    {
        return $this->state === 'JOB_STATE_PENDING';
    }

    public function isRunning(): bool
    {
        return $this->state === 'JOB_STATE_RUNNING';
    }
}
