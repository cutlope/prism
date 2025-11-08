<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;
use Tests\Fixtures\FixtureResponse;

it('can create a batch job with inline requests', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-inline');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        [
            'model' => 'models/gemini-1.5-flash-002',
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'What is the capital of France?']]],
            ],
        ],
        [
            'model' => 'models/gemini-1.5-flash-002',
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'What is the capital of Spain?']]],
            ],
        ],
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

it('can create a batch job with cached content', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/batch-create-with-cache');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $requests = [
        [
            'model' => 'models/gemini-1.5-flash-002',
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'What is the main topic?']]],
            ],
            'cachedContent' => 'cachedContents/kmvaiarhyq2g',
        ],
    ];

    $batchJob = $provider->createBatchInline('gemini-1.5-flash-002', $requests);

    expect($batchJob->name)->toBe('batches/batch-job-456');
    expect($batchJob->metadata)->toHaveKey('cachedContent');
    expect($batchJob->metadata['cachedContent'])->toBe('cachedContents/kmvaiarhyq2g');
});
