<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;
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
