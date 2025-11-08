<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiFile;

class File
{
    public function __construct(
        protected PendingRequest $client,
    ) {}

    /**
     * Upload a file to Gemini Files API
     */
    public function upload(string $filePath, ?string $displayName = null, ?string $mimeType = null): GeminiFile
    {
        // First, get the upload URL
        $metadata = Arr::whereNotNull([
            'file' => [
                'displayName' => $displayName ?? basename($filePath),
            ],
        ]);

        $uploadResponse = $this->client
            ->withHeaders(['X-Goog-Upload-Protocol' => 'resumable'])
            ->withHeader('X-Goog-Upload-Command', 'start')
            ->withHeader('X-Goog-Upload-Header-Content-Length', filesize($filePath))
            ->withHeader('X-Goog-Upload-Header-Content-Type', $mimeType ?? mime_content_type($filePath) ?: 'application/octet-stream')
            ->post('/upload/v1beta/files', $metadata);

        $uploadUrl = $uploadResponse->header('X-Goog-Upload-URL');

        // Upload the actual file content
        $fileContent = file_get_contents($filePath);

        $finalizeResponse = $this->client
            ->withHeaders([
                'Content-Length' => strlen($fileContent),
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])
            ->withBody($fileContent, $mimeType ?? mime_content_type($filePath) ?: 'application/octet-stream')
            ->post($uploadUrl);

        return GeminiFile::fromResponse($finalizeResponse->json('file'));
    }

    /**
     * Get file metadata
     */
    public function get(string $fileName): GeminiFile
    {
        $response = $this->client->get("/files/{$fileName}");

        return GeminiFile::fromResponse($response->json());
    }

    /**
     * List all files
     *
     * @return GeminiFile[]
     */
    public function list(int $pageSize = 100): array
    {
        $response = $this->client->get('/files', [
            'pageSize' => $pageSize,
        ]);

        $files = data_get($response->json(), 'files', []);

        return array_map(
            GeminiFile::fromResponse(...),
            $files
        );
    }

    /**
     * Delete a file
     */
    public function delete(string $fileName): bool
    {
        $response = $this->client->delete("/files/{$fileName}");

        return $response->successful();
    }
}
