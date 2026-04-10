<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;

arch()->expect([
    'Providers\Gemini\Handlers\Text',
    'Providers\Gemini\Handlers\Structured',
])
    ->toUseTrait(ValidatesResponse::class);

it('throws a PrismRateLimitedException with a 429 response code for text and structured', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::Gemini, 'fake-model')
        ->withPrompt('Hello world!')
        ->asText();

})->throws(PrismRateLimitedException::class);

it('throws a PrismRateLimitedException with a 429 response code for embeddings', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::embeddings()
        ->using(Provider::Gemini, 'fake-model')
        ->fromInput('Hello world!')
        ->asEmbeddings();

})->throws(PrismRateLimitedException::class);

it('parses Gemini daily quota rate limit details', function (): void {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'You exceeded your current quota. Please retry in 735.243503ms.',
                'status' => 'RESOURCE_EXHAUSTED',
                'details' => [
                    [
                        '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                        'violations' => [
                            [
                                'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
                                'quotaValue' => '1500',
                            ],
                        ],
                    ],
                    [
                        '@type' => 'type.googleapis.com/google.rpc.RetryInfo',
                        'retryDelay' => '0s',
                    ],
                ],
            ],
        ], 429),
    ])->preventStrayRequests();

    try {
        Prism::text()
            ->using(Provider::Gemini, 'fake-model')
            ->withPrompt('Hello world!')
            ->asText();
    } catch (PrismRateLimitedException $e) {
        expect($e->retryAfter)->toEqual(1);
        expect($e->rateLimits)->toHaveCount(1);
        expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
        expect($e->rateLimits[0]->name)->toEqual('generate_content_free_tier_requests');
        expect($e->rateLimits[0]->limit)->toEqual(1500);
    }
});

it('parses Gemini token per minute rate limit details', function (): void {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'You exceeded your current quota. Please retry in 35.458759309s.',
                'status' => 'RESOURCE_EXHAUSTED',
                'details' => [
                    [
                        '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                        'violations' => [
                            [
                                'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_paid_tier_input_token_count',
                                'quotaValue' => '16000',
                            ],
                        ],
                    ],
                    [
                        '@type' => 'type.googleapis.com/google.rpc.RetryInfo',
                        'retryDelay' => '35s',
                    ],
                ],
            ],
        ], 429),
    ])->preventStrayRequests();

    try {
        Prism::text()
            ->using(Provider::Gemini, 'fake-model')
            ->withPrompt('Hello world!')
            ->asText();
    } catch (PrismRateLimitedException $e) {
        expect($e->retryAfter)->toEqual(35);
        expect($e->rateLimits)->toHaveCount(1);
        expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
        expect($e->rateLimits[0]->name)->toEqual('generate_content_paid_tier_input_token_count');
        expect($e->rateLimits[0]->limit)->toEqual(16000);
    }
});

it('throws a PrismRateLimitedException with a 429 response code for cache', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $provider->cache(
        model: 'gemini-1.5-flash-002',
        messages: [
            new UserMessage('', [
                Document::fromLocalPath('tests/Fixtures/long-document.pdf'),
            ]),
        ],
        systemPrompts: [
            new SystemMessage('You are a legal analyst.'),
        ],
        ttl: 60
    );

})->throws(PrismRateLimitedException::class);
