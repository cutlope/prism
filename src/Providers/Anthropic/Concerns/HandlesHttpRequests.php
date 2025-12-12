<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Exceptions\PrismException;

trait HandlesHttpRequests
{
    protected Response $httpResponse;

    /**
     * @return array<string, mixed>
     */
    abstract public static function buildHttpRequestPayload(PrismRequest $request): array;

    protected function sendRequest(): void
    {
        $payload = static::buildHttpRequestPayload($this->request);

        if (config('prism.debug.requests')) {
            Log::debug('Anthropic request payload', [
                'model' => $this->request->model(),
                'payload' => $payload,
            ]);
        }

        /** @var Response $response */
        $response = $this->client->post('messages', $payload);

        if (config('prism.debug.responses')) {
            Log::debug('Anthropic response payload', [
                'model' => $this->request->model(),
                'response' => $response->json(),
            ]);
        }

        $this->httpResponse = $response;

        $this->handleResponseErrors();
    }

    protected function handleResponseErrors(): void
    {
        $data = $this->httpResponse->json();

        if (data_get($data, 'type') === 'error') {
            throw PrismException::providerResponseError(vsprintf(
                'Anthropic Error: [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message'),
                ]
            ));
        }
    }
}
