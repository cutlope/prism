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
        protected string $apiKey,
    ) {}

    /**
     * Upload a file to Gemini Files API
     */
    public function upload(string $filePath, ?string $displayName = null, ?string $mimeType = null): GeminiFile
    {
        $detectedMimeType = $mimeType ?? mime_content_type($filePath) ?: 'application/octet-stream';

        $fileName = basename($filePath);
        $fileSize = filesize($filePath);

        // First, get the upload URL
        $metadata = [
            'file' => Arr::whereNotNull([
                'display_name' => $displayName ?? $fileName,
            ]),
        ];

        $uploadResponse = $this->client
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $fileSize,
                'X-Goog-Upload-Header-Content-Type' => $detectedMimeType,
                'X-Goog-Upload-File-Name' => $fileName,
            ])
            ->post('upload/v1beta/files', $metadata);

        $uploadUrl = $uploadResponse->header('x-goog-upload-url');

        if (! $uploadUrl) {
            throw new \RuntimeException('Failed to get upload URL from response headers');
        }

        // Upload the actual file content using the full upload URL
        $fileContent = file_get_contents($filePath);

        $finalizeResponse = \Illuminate\Support\Facades\Http::withHeaders([
            'Content-Length' => (string) strlen($fileContent),
            'X-Goog-Upload-Offset' => '0',
            'X-Goog-Upload-Command' => 'upload, finalize',
            'x-goog-api-key' => $this->apiKey,
        ])
            ->withBody($fileContent)
            ->post($uploadUrl);

        if (! $finalizeResponse->successful()) {
            throw new \RuntimeException(
                'File upload failed: '.$finalizeResponse->body()
            );
        }

        $fileData = $finalizeResponse->json('file');

        if ($fileData === null) {
            throw new \RuntimeException(
                'Invalid response from file upload: '.$finalizeResponse->body()
            );
        }

        return GeminiFile::fromResponse($fileData);
    }

    /**
     * Get file metadata
     *
     * @param  string  $fileName  File name/ID (with or without 'files/' prefix)
     */
    public function get(string $fileName): GeminiFile
    {
        // Ensure fileName has the correct format
        $fileName = str_starts_with($fileName, 'files/') ? $fileName : "files/{$fileName}";

        $response = $this->client->get("v1beta/{$fileName}");

        return GeminiFile::fromResponse($response->json());
    }

    /**
     * List all files
     *
     * @return GeminiFile[]
     */
    public function list(int $pageSize = 100): array
    {
        $response = $this->client->get('v1beta/files', [
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
     *
     * @param  string  $fileName  File name/ID (with or without 'files/' prefix)
     */
    public function delete(string $fileName): bool
    {
        // Ensure fileName has the correct format
        $fileName = str_starts_with($fileName, 'files/') ? $fileName : "files/{$fileName}";

        $response = $this->client->delete("v1beta/{$fileName}");

        return $response->successful();
    }
}
