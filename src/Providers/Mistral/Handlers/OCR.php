<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Mistral\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Mistral\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Mistral\Maps\DocumentMapper;
use Prism\Prism\Providers\Mistral\ValueObjects\OCRResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Media\Document;

class OCR
{
    use CallsTools;
    use MapsFinishReason;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        protected string $model,
        protected Document $document,
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    /**
     * @throws PrismRateLimitedException
     * @throws PrismException
     */
    public function handle(): OCRResponse
    {
        $response = $this->sendRequest();

        return OCRResponse::fromResponse($this->model, $response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PrismException
     */
    protected function sendRequest(): array
    {
        $payload = [
            'model' => $this->model,
            'document' => (new DocumentMapper($this->document))->toPayload(),
        ];

        if (config('prism.debug.requests')) {
            Log::debug('Mistral request payload', [
                'model' => $this->model,
                'payload' => $payload,
            ]);
        }

        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this->client->post('/ocr', $payload);

        if (config('prism.debug.responses')) {
            Log::debug('Mistral response payload', [
                'model' => $this->model,
                'response' => $response->json(),
            ]);
        }

        return $response->json();
    }
}
