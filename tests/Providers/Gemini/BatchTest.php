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

it('handles mixed explicit and default batch keys without collision', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-inline');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Explicit key collision test')
            ->withBatchKey('request-2')  // Explicit key that matches default pattern
            ->toRequest(),
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('No explicit key')
            ->toRequest(),  // Will auto-generate "request-1"
        Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash-002')
            ->withPrompt('Another no key')
            ->toRequest(),  // Would auto-generate "request-2" but should detect collision
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-123');
    expect($batchJob->model)->toBe('models/gemini-1.5-flash-002');
    expect($batchJob->state)->toBe('JOB_STATE_PENDING');
});

it('can parse batch results from output file', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-output-results');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $results = $provider->getBatchResults('https://generativelanguage.googleapis.com/v1beta/files/output-123');

    // Verify all results are present
    expect($results)->toHaveKeys(['request-1', 'request-2', 'structured-1']);

    // Verify text response
    expect($results['request-1'])->toHaveKey('success', true);
    expect($results['request-1'])->toHaveKey('type', 'text');
    expect($results['request-1'])->toHaveKey('text', 'Paris is the capital of France.');
    expect($results['request-1'])->toHaveKey('usage');
    expect($results['request-1']['usage']['promptTokens'])->toBe(15);
    expect($results['request-1']['usage']['completionTokens'])->toBe(8);

    // Verify cached response
    expect($results['request-2'])->toHaveKey('success', true);
    expect($results['request-2']['usage']['cacheReadInputTokens'])->toBe(10);

    // Verify structured response
    expect($results['structured-1'])->toHaveKey('success', true);
    expect($results['structured-1'])->toHaveKey('type', 'structured');
    expect($results['structured-1'])->toHaveKey('structured');
    expect($results['structured-1']['structured'])->toBe(['name' => 'John Doe', 'email' => 'john@example.com']);
});
