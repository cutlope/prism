<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\ValueObjects;

use Illuminate\Support\Carbon;

readonly class GeminiFile
{
    public function __construct(
        public string $name,
        public string $displayName,
        public string $mimeType,
        public int $sizeBytes,
        public Carbon $createTime,
        public Carbon $updateTime,
        public Carbon $expirationTime,
        public string $sha256Hash,
        public string $uri,
        public string $state,
        public ?string $downloadUri = null,
        public ?string $source = null,
        public ?array $videoMetadata = null,
    ) {}

    /**
     * @param  array<string,mixed>  $response
     */
    public static function fromResponse(array $response): self
    {
        return new self(
            name: data_get($response, 'name', ''),
            displayName: data_get($response, 'displayName', ''),
            mimeType: data_get($response, 'mimeType', ''),
            sizeBytes: data_get($response, 'sizeBytes', 0),
            createTime: Carbon::parse(data_get($response, 'createTime')),
            updateTime: Carbon::parse(data_get($response, 'updateTime')),
            expirationTime: Carbon::parse(data_get($response, 'expirationTime')),
            sha256Hash: data_get($response, 'sha256Hash', ''),
            uri: data_get($response, 'uri', ''),
            state: data_get($response, 'state', ''),
            downloadUri: data_get($response, 'downloadUri'),
            source: data_get($response, 'source'),
            videoMetadata: data_get($response, 'videoMetadata'),
        );
    }

    /**
     * Check if file is ready to use (state is ACTIVE)
     */
    public function isActive(): bool
    {
        return $this->state === 'ACTIVE';
    }

    /**
     * Check if file is still being processed
     */
    public function isProcessing(): bool
    {
        return $this->state === 'PROCESSING';
    }

    /**
     * Check if file processing failed
     */
    public function isFailed(): bool
    {
        return $this->state === 'FAILED';
    }
}
