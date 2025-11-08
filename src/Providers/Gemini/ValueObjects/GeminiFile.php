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
            videoMetadata: data_get($response, 'videoMetadata'),
        );
    }
}
