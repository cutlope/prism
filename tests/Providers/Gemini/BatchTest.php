<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

it('can create a batch job with inline requests', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-inline');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withSystemPrompt('You are a helpful assistant.')
            ->withPrompt('What is the capital of France?')
            ->usingTemperature(0.5)
            ->withMaxTokens(1000)
            ->withBatchKey('france-capital')
            ->toRequest(),
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('What is the capital of Spain?')
            ->withBatchKey('spain-capital')
            ->toRequest(),
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-123');
    expect($batchJob->model)->toBe('models/gemini-1.5-flash-002');
    expect($batchJob->state)->toBe('JOB_STATE_PENDING');
    expect($batchJob->isPending())->toBeTrue();
    expect($requests[0]->batchKey())->toBe('france-capital');
    expect($requests[1]->batchKey())->toBe('spain-capital');
});

it('can get a batch job status', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-get-succeeded');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $batchJob = $provider->getBatch('batches/batch-job-123');

    expect($batchJob->name)->toBe('batches/batch-job-123');
    expect($batchJob->state)->toBe('JOB_STATE_SUCCEEDED');
    expect($batchJob->isCompleted())->toBeTrue();
    expect($batchJob->outputFileUri)->toBe('https://generativelanguage.googleapis.com/v1beta/files/output-123');
});

it('can create batch job with structured requests', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-structured');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $schema = new ObjectSchema(
        'user_info',
        'User information',
        [
            new StringSchema('name', 'User name', true),
            new StringSchema('email', 'User email', true),
        ],
        ['name', 'email']
    );

    $requests = [
        Prism::structured()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withSchema($schema)
            ->withPrompt('Extract user info: John Doe, john@example.com')
            ->toRequest(),
        Prism::structured()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withSchema($schema)
            ->withPrompt('Extract user info: Jane Smith, jane@example.com')
            ->toRequest(),
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-789');
    expect($batchJob->model)->toBe('models/gemini-1.5-flash-002');
    expect($batchJob->state)->toBe('JOB_STATE_PENDING');
});

it('handles requests without batch keys for inline batches', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-inline');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Request with explicit key')
            ->withBatchKey('custom-key')
            ->toRequest(),
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Request without key')
            ->toRequest(),
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-123');
    expect($batchJob->state)->toBe('JOB_STATE_PENDING');
});

it('can parse batch results from output file', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-output-results');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $results = $provider->getBatchResults('https://generativelanguage.googleapis.com/v1beta/files/output-123');

    expect($results)->toHaveKeys(['request-1', 'request-2', 'structured-1']);
    expect($results['request-1']['success'])->toBe(true);
    expect($results['request-1']['type'])->toBe('text');
    expect($results['request-1']['text'])->toBe('Paris is the capital of France.');
    expect($results['request-1']['usage']['promptTokens'])->toBe(15);
    expect($results['request-2']['usage']['cacheReadInputTokens'])->toBe(10);
    expect($results['structured-1']['type'])->toBe('structured');
    expect($results['structured-1']['structured'])->toBe(['name' => 'John Doe', 'email' => 'john@example.com']);
});

it('parses inline batch results as indexed array', function (): void {
    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $inlineResponses = [
        [
            'metadata' => ['key' => 'custom-key'],
            'response' => [
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Response 1']]], 'finishReason' => 'STOP'],
                ],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            ],
        ],
        [
            'response' => [
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Response 2']]], 'finishReason' => 'STOP'],
                ],
                'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 4, 'totalTokenCount' => 12],
            ],
        ],
    ];

    $results = $provider->getBatchResults($inlineResponses);

    expect($results)->toHaveCount(2);
    expect($results[0]['text'])->toBe('Response 1');
    expect($results[0]['batchKey'])->toBe('custom-key');
    expect($results[1]['text'])->toBe('Response 2');
    expect($results[1])->not->toHaveKey('batchKey');
});

it('throws error when converting requests to JSONL without batch keys', function (): void {
    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Request with key')
            ->withBatchKey('has-key')
            ->toRequest(),
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Request without key')
            ->toRequest(),
    ];

    expect(fn () => $provider->convertRequestsToJsonl($requests))
        ->toThrow(PrismException::class, 'Batch key is required for file-based batches. Request at index 1 is missing a batch key.');
});
