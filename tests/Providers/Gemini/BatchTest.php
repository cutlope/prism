<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

it('can create a batch job with inline requests using fluent API', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-inline');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('What is the capital of France?')
            ->toRequest(),
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('What is the capital of Spain?')
            ->toRequest(),
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-123');
    expect($batchJob->model)->toBe('models/gemini-1.5-flash-002');
    expect($batchJob->state)->toBe('JOB_STATE_PENDING');
    expect($batchJob->isPending())->toBeTrue();
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

it('can create a batch job with cached content using fluent API', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-with-cache');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('What is the main topic?')
            ->withProviderOptions(['cachedContentName' => 'cachedContents/kmvaiarhyq2g'])
            ->toRequest(),
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-456');
    expect($batchJob->metadata)->toHaveKey('cachedContent');
    expect($batchJob->metadata['cachedContent'])->toBe('cachedContents/kmvaiarhyq2g');
});

it('can create batch job with complex options using fluent API', function (): void {
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
            ->toRequest(),
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-123');
    expect($batchJob->model)->toBe('models/gemini-1.5-flash-002');
    expect($batchJob->state)->toBe('JOB_STATE_PENDING');
});

it('can create batch job with structured requests using fluent API', function (): void {
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

it('can create batch job mixing text and structured requests', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-inline');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $schema = new ObjectSchema(
        'analysis',
        'Analysis result',
        [
            new StringSchema('summary', 'Summary', true),
        ],
        ['summary']
    );

    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('What is AI?')
            ->toRequest(),
        Prism::structured()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withSchema($schema)
            ->withPrompt('Analyze this text: AI is transforming technology')
            ->toRequest(),
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-123');
    expect($batchJob->model)->toBe('models/gemini-1.5-flash-002');
    expect($batchJob->state)->toBe('JOB_STATE_PENDING');
});

it('can create batch job with custom batch keys', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-inline');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('What is the capital of France?')
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

    // Verify batch keys are set
    expect($requests[0]->batchKey())->toBe('france-capital');
    expect($requests[1]->batchKey())->toBe('spain-capital');
});

it('handles requests without batch keys (keys are optional for inline batches)', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-inline');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    // Mix of requests with and without explicit keys
    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Request with explicit key')
            ->withBatchKey('custom-key')
            ->toRequest(),
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Request without key')
            ->toRequest(),  // No key - this is fine for inline batches
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Another without key')
            ->toRequest(),  // No key - also fine
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-123');
    expect($batchJob->model)->toBe('models/gemini-1.5-flash-002');
    expect($batchJob->state)->toBe('JOB_STATE_PENDING');
});

it('can parse batch results from output file (returns indexed array)', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-output-results');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $results = $provider->getBatchResults('https://generativelanguage.googleapis.com/v1beta/files/output-123');

    // File-based batches now return indexed array (same as inline batches)
    expect($results)->toBeArray();
    expect($results)->toHaveCount(3);

    // First result - verify it has the batch key included
    expect($results[0])->toHaveKey('success', true);
    expect($results[0])->toHaveKey('type', 'text');
    expect($results[0])->toHaveKey('text', 'Paris is the capital of France.');
    expect($results[0])->toHaveKey('batchKey', 'request-1');  // Key included in result
    expect($results[0])->toHaveKey('usage');
    expect($results[0]['usage']['promptTokens'])->toBe(15);
    expect($results[0]['usage']['completionTokens'])->toBe(8);

    // Second result - verify cached response
    expect($results[1])->toHaveKey('success', true);
    expect($results[1])->toHaveKey('batchKey', 'request-2');
    expect($results[1]['usage']['cacheReadInputTokens'])->toBe(10);

    // Third result - verify structured response
    expect($results[2])->toHaveKey('success', true);
    expect($results[2])->toHaveKey('type', 'structured');
    expect($results[2])->toHaveKey('batchKey', 'structured-1');
    expect($results[2])->toHaveKey('structured');
    expect($results[2]['structured'])->toBe(['name' => 'John Doe', 'email' => 'john@example.com']);
});

it('parses inline batch results as indexed array (no order assumption)', function (): void {
    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    // Simulate inline batch responses
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
            'response' => [  // No metadata - no explicit key
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Response 2']]], 'finishReason' => 'STOP'],
                ],
                'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 4, 'totalTokenCount' => 12],
            ],
        ],
        [
            'response' => [  // No metadata - no explicit key
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Response 3']]], 'finishReason' => 'STOP'],
                ],
                'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 6, 'totalTokenCount' => 18],
            ],
        ],
    ];

    $results = $provider->getBatchResults($inlineResponses);

    // Inline batches return indexed array in the order the API provides them
    expect($results)->toBeArray();
    expect($results)->toHaveCount(3);

    // First result has explicit key
    expect($results[0])->toHaveKey('text', 'Response 1');
    expect($results[0])->toHaveKey('batchKey', 'custom-key');  // Key is included in result
    expect($results[0]['usage']['totalTokens'])->toBe(15);

    // Second and third results have no keys (keys are optional)
    expect($results[1])->toHaveKey('text', 'Response 2');
    expect($results[1])->not->toHaveKey('batchKey');  // No key in result
    expect($results[1]['usage']['totalTokens'])->toBe(12);

    expect($results[2])->toHaveKey('text', 'Response 3');
    expect($results[2])->not->toHaveKey('batchKey');
    expect($results[2]['usage']['totalTokens'])->toBe(18);
});
