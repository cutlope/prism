<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;
use Tests\Fixtures\FixtureResponse;

it('can list files from Files API', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/file-list');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $files = $provider->listFiles();

    expect($files)->toHaveCount(2);
    expect($files[0]->name)->toBe('files/test-file-123');
    expect($files[0]->displayName)->toBe('test-document.pdf');
    expect($files[0]->mimeType)->toBe('application/pdf');
    expect($files[0]->state)->toBe('ACTIVE');
    expect($files[1]->name)->toBe('files/test-file-456');
});
