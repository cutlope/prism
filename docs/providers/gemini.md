# Gemini

## Configuration

```php
'gemini' => [
    'api_key' => env('GEMINI_API_KEY', ''),
    'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
],
```

## Search grounding

Google Gemini offers built-in search grounding capabilities that allow your AI to search the web for real-time information. This is a provider tool that uses Google's search infrastructure. For more information about the difference between custom tools and provider tools, see [Tools & Function Calling](/core-concepts/tools-function-calling#provider-tools).

You may enable Google search grounding on text requests using withProviderTools:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\ProviderTool;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.0-flash')
    ->withPrompt('What is the stock price of Google right now?')
    // Enable search grounding
    ->withProviderTools([
            new ProviderTool('google_search')
        ])
    ->asText();
```

If you use search groundings, Google require you meet certain [display requirements](https://ai.google.dev/gemini-api/docs/grounding/search-suggestions).

The data you need to meet these display requirements, and to build e.g. footnote functionality will be saved to the response's `additionalContent` property.

```php
// The Google supplied and styled widget to click through to results.
$response->additionalContent['searchEntryPoint'];

// The search queries made by the model
$response->additionalContent['searchQueries'];

// The citations data is available as an array of MessagePartWithCitations
$response->additionalContent['citations'];
```

`citations` is an array of `MessagePartWithCitations`, which you can use to build up footnotes as follows:

```php
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Citation;

$text = '';
$footnotes = [];

$footnoteId = 1;

/** @var MessagePartWithCitations $part */
foreach ($response->additionalContent['citations'] as $part) {
    $text .= $part->outputText;
    
    /** @var Citation $citation */
    foreach ($part->citations as $citation) {
        $footnotes[] = [
            'id' => $footnoteId,
            'title' => $citation->sourceTitle,
            'uri' => $citation->source,
        ];

        $text .= '<sup><a href="#footnote-'.$footnoteId.'">'.$footnoteId.'</a></sup>';

        $footnoteId++;
    }
}

// Pass $text and $footnotes to your frontend.
```

## Structured Output

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema(
    name: 'movie_review',
    description: 'A structured movie review',
    properties: [
        new StringSchema('title', 'The movie title'),
        new StringSchema('rating', 'Rating out of 5 stars'),
        new StringSchema('summary', 'Brief review summary'),
    ],
    requiredFields: ['title', 'rating', 'summary']
);

$response = Prism::structured()
    ->using(Provider::Gemini, 'gemini-2.0-flash')
    ->withSchema($schema)
    ->withPrompt('Review the movie Inception')
    ->asStructured();

// Access structured data
dump($response->structured);
```

### Combining Tools with Structured Output

Gemini natively supports combining custom tools with structured output. The AI can call tools to gather data, then return a structured response:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

$schema = new ObjectSchema(
    name: 'weather_analysis',
    description: 'Analysis of weather conditions',
    properties: [
        new StringSchema('summary', 'Summary of the weather'),
        new StringSchema('recommendation', 'Recommendation based on weather'),
    ],
    requiredFields: ['summary', 'recommendation']
);

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::structured()
    ->using('gemini', 'gemini-2.0-flash')
    ->withSchema($schema)
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
    ->asStructured();

// Access structured output
dump($response->structured);

// Access tool execution details
foreach ($response->toolCalls as $toolCall) {
    echo "Called: {$toolCall->name}\n";
}
```

> [!IMPORTANT]
> When combining tools with structured output, set `maxSteps` to at least 2.

For complete documentation on combining tools with structured output, see [Structured Output - Combining with Tools](/core-concepts/structured-output#combining-structured-output-with-tools).

## Caching

Prism supports Gemini prompt caching, though due to Gemini requiring you first upload the cached content, it works a little differently to other providers.

To store content in the cache, use the Gemini provider cache method as follows:

```php

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/** @var Gemini */
$provider = Prism::provider(Provider::Gemini);

$object = $provider->cache(
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
```

Then reference that object's name in your request using withProviderOptions:

```php
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash-002')
    ->withProviderOptions(['cachedContentName' => $object->name])
    ->withPrompt('In no more than 100 words, what is the document about?')
    ->asText();
```

## Files API

Gemini's Files API allows you to upload files to Google's servers for use in generation requests. Files are automatically deleted after 48 hours (or sooner if you specify a custom TTL). This is particularly useful for large files or when using the same file across multiple requests.

### Uploading Files

Upload a file from your local filesystem:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;

/** @var Gemini */
$provider = Prism::provider(Provider::Gemini);

$file = $provider->uploadFile(
    filePath: '/path/to/document.pdf',
    displayName: 'My Document',  // Optional
    mimeType: 'application/pdf'  // Optional, auto-detected if not provided
);

echo $file->name;        // files/abc123...
echo $file->uri;         // https://generativelanguage.googleapis.com/v1beta/files/abc123...
echo $file->sizeBytes;   // File size in bytes
echo $file->state;       // PROCESSING, ACTIVE, or FAILED
```

### File States

Uploaded files go through different states. Use helper methods to check the status:

```php
if ($file->isProcessing()) {
    echo "File is still being processed...";
}

if ($file->isActive()) {
    echo "File is ready to use!";
    // Use the file in your requests
}

if ($file->isFailed()) {
    echo "File processing failed";
}
```

### Getting File Metadata

Retrieve information about a previously uploaded file:

```php
// Using full file name
$file = $provider->getFile('files/abc123...');

// Or just the ID (prefix is added automatically)
$file = $provider->getFile('abc123...');
```

### Listing Files

List all uploaded files:

```php
$files = $provider->listFiles();

foreach ($files as $file) {
    echo "{$file->displayName}: {$file->state}\n";
}
```

### Deleting Files

Delete a file before its automatic expiration:

```php
// Using full file name
$provider->deleteFile('files/abc123...');

// Or just the ID
$provider->deleteFile('abc123...');
```

### Using Uploaded Files in Requests

Once a file is uploaded and active, you can reference it by URI in your generation requests:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

$file = $provider->uploadFile('/path/to/large-document.pdf');

// Wait for file to be active
while ($file->isProcessing()) {
    sleep(1);
    $file = $provider->getFile($file->name);
}

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([
        new UserMessage('Summarize this document', [
            Document::fromUrl($file->uri),
        ]),
    ])
    ->asText();
```

## Batch API

Gemini's Batch API allows you to send multiple requests in a single batch operation, reducing costs by up to 50% compared to standard requests. Batch requests are processed asynchronously, making them ideal for non-time-sensitive workloads.

### Creating Inline Batch Requests

Create batch requests directly using Prism's fluent API:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;

/** @var Gemini */
$provider = Prism::provider(Provider::Gemini);

// Create individual requests using Prism's fluent API
$requests = [
    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is the capital of France?')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is the capital of Spain?')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is the capital of Italy?')
        ->toRequest(),
];

// Submit the batch
$batch = $provider->createBatchInline(
    model: 'gemini-1.5-flash',
    requests: $requests,
    displayName: 'Capital Cities Batch'  // Optional
);

echo $batch->name;   // models/.../batchJobs/abc123...
echo $batch->state;  // PENDING, RUNNING, SUCCEEDED, or FAILED
```

### Using Structured Requests in Batches

You can include structured output requests in your batches:

```php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema(
    name: 'city_info',
    description: 'Information about a city',
    properties: [
        new StringSchema('city', 'The city name'),
        new StringSchema('country', 'The country name'),
        new StringSchema('population', 'The population'),
    ],
    requiredFields: ['city', 'country']
);

$requests = [
    Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withSchema($schema)
        ->withPrompt('Get information about Paris')
        ->toRequest(),

    Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withSchema($schema)
        ->withPrompt('Get information about Madrid')
        ->toRequest(),
];

$batch = $provider->createBatchInline(
    model: 'gemini-1.5-flash',
    requests: $requests
);
```

### Mixing Text and Structured Requests

You can combine both text and structured requests in the same batch:

```php
$requests = [
    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('Write a haiku about the ocean')
        ->toRequest(),

    Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withSchema($schema)
        ->withPrompt('Get information about Tokyo')
        ->toRequest(),
];

$batch = $provider->createBatchInline(
    model: 'gemini-1.5-flash',
    requests: $requests
);
```

### Using Batch Keys

Batch keys allow you to identify specific requests in the batch output. This is useful when you need to match responses to their corresponding requests:

```php
$requests = [
    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is the capital of France?')
        ->withBatchKey('france-capital')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is the capital of Spain?')
        ->withBatchKey('spain-capital')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is the capital of Italy?')
        ->withBatchKey('italy-capital')
        ->toRequest(),
];

$batch = $provider->createBatchInline(
    model: 'gemini-1.5-flash',
    requests: $requests
);

// When the batch completes, each response will be tagged with its corresponding key
// This allows you to easily match responses to their original requests
```

Batch keys work with both text and structured requests:

```php
$requests = [
    Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withSchema($schema)
        ->withPrompt('Analyze customer feedback: "Great product!"')
        ->withBatchKey('feedback-1')
        ->toRequest(),

    Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withSchema($schema)
        ->withPrompt('Analyze customer feedback: "Needs improvement"')
        ->withBatchKey('feedback-2')
        ->toRequest(),
];
```

### Creating File-Based Batch Requests

For very large batches, you can upload a JSONL file containing your requests:

```php
// First, upload your JSONL file
$file = $provider->uploadFile(
    filePath: '/path/to/batch-requests.jsonl',
    mimeType: 'application/jsonl'
);

// Wait for the file to be processed
while ($file->isProcessing()) {
    sleep(1);
    $file = $provider->getFile($file->name);
}

// Create batch from the uploaded file
$batch = $provider->createBatchFromFile(
    model: 'gemini-1.5-flash',
    fileName: $file->name,
    displayName: 'Large Batch Job'  // Optional
);
```

The JSONL file should contain one request per line. Each line must include a user-defined `key` field and a `request` field. The key is used to match responses to requests in the output:

```json
{"key": "request-1", "request": {"contents": [{"parts": [{"text": "What is AI?"}]}]}}
{"key": "request-2", "request": {"contents": [{"parts": [{"text": "What is ML?"}]}]}}
{"key": "request-3", "request": {"contents": [{"parts": [{"text": "What is NLP?"}]}]}}
```

You can also include `generation_config` and other request parameters:

```json
{"key": "creative-story", "request": {"contents": [{"parts": [{"text": "Write a story about a dragon"}]}], "generation_config": {"temperature": 0.9}}}
{"key": "technical-doc", "request": {"contents": [{"parts": [{"text": "Explain quantum computing"}]}], "generation_config": {"temperature": 0.3}}}
```

The maximum allowed file size is 2GB.

### Automatic JSONL Generation from Prism Requests

For large batches, you can use `createBatchFromRequests()` which automatically generates the JSONL file, uploads it, and creates the batch job:

```php
$requests = [
    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is quantum computing?')
        ->withBatchKey('quantum-q1')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is machine learning?')
        ->withBatchKey('ml-q1')
        ->toRequest(),

    // ... hundreds or thousands more requests
];

// Automatically generates JSONL, uploads file, and creates batch
$batch = $provider->createBatchFromRequests(
    model: 'gemini-1.5-flash',
    requests: $requests,
    displayName: 'Large Analysis Batch'  // Optional
);

echo $batch->name;   // models/.../batchJobs/abc123...
echo $batch->state;  // PENDING
```

This method:
1. Converts your Prism requests to JSONL format
2. Creates a temporary file with the JSONL content
3. Uploads the file via the Files API
4. Waits for the file to be processed (up to 30 seconds)
5. Creates the batch job using the uploaded file
6. Cleans up the temporary file

If you don't specify batch keys with `withBatchKey()`, automatic keys will be generated (`request-0`, `request-1`, etc.).

This is particularly useful when you have:
- Hundreds or thousands of requests
- Requests generated dynamically from a database or other source
- Complex request configurations that are easier to build with Prism's fluent API

### Batch Request Options

You can use all standard Prism options in batch requests:

```php
$requests = [
    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('Write a creative story')
        ->withMaxTokens(1000)
        ->withTemperature(0.9)
        ->withSystemPrompt('You are a creative storyteller')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('Write a technical manual')
        ->withMaxTokens(500)
        ->withTemperature(0.3)
        ->toRequest(),
];

$batch = $provider->createBatchInline(
    model: 'gemini-1.5-flash',
    requests: $requests
);
```

### Using Caching with Batch Requests

Combine context caching with batch requests for maximum efficiency:

```php
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

// First, create a cached context
$cachedContent = $provider->cache(
    model: 'gemini-1.5-flash',
    messages: [
        new UserMessage('', [
            Document::fromLocalPath('/path/to/large-document.pdf'),
        ]),
    ],
    systemPrompts: [
        new SystemMessage('You are a legal analyst.'),
    ],
    ttl: 3600  // 1 hour
);

// Create batch requests that reference the cached content
$requests = [
    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withProviderOptions(['cachedContentName' => $cachedContent->name])
        ->withPrompt('What are the key legal risks in section 1?')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withProviderOptions(['cachedContentName' => $cachedContent->name])
        ->withPrompt('What are the key legal risks in section 2?')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withProviderOptions(['cachedContentName' => $cachedContent->name])
        ->withPrompt('What are the key legal risks in section 3?')
        ->toRequest(),
];

$batch = $provider->createBatchInline(
    model: 'gemini-1.5-flash',
    requests: $requests,
    displayName: 'Legal Analysis Batch'
);
```

This combination provides:
- **50% cost reduction** from batch API
- **Reduced input token costs** from context caching
- **Consistent context** across all requests in the batch

### Managing Batch Jobs

Check the status of a batch job:

```php
$batch = $provider->getBatch($batch->name);

echo $batch->state;        // PENDING, RUNNING, SUCCEEDED, or FAILED
echo $batch->displayName;
```

Use helper methods to check batch status:

```php
if ($batch->isPending()) {
    echo "Batch is queued for processing";
}

if ($batch->isRunning()) {
    echo "Batch is currently being processed";
}

if ($batch->isCompleted()) {
    echo "Batch has finished successfully!";

    // Download results from the output URI
    if ($batch->outputUri) {
        echo "Results available at: {$batch->outputUri}";
    }
}

if ($batch->isFailed()) {
    echo "Batch processing failed";
}
```

List all batch jobs:

```php
$batches = $provider->listBatches();

foreach ($batches as $batch) {
    echo "{$batch->displayName}: {$batch->state}\n";
}
```

Cancel a running batch job:

```php
$provider->cancelBatch($batch->name);
```

Delete a completed batch job:

```php
$provider->deleteBatch($batch->name);
```

### Parsing Batch Results

Once a batch job is completed, you can parse the results from the output file:

```php
// Wait for batch to complete
$batch = $provider->getBatch($batchName);

while (!$batch->isCompleted() && !$batch->isFailed()) {
    sleep(10);
    $batch = $provider->getBatch($batchName);
}

if ($batch->isCompleted() && $batch->outputUri) {
    // Parse the results
    $results = $provider->getBatchResults($batch->outputUri);

    // Results are keyed by the request keys you specified
    foreach ($results as $key => $result) {
        if ($result['success']) {
            echo "Request {$key}:\n";
            echo "Type: {$result['type']}\n";  // 'text' or 'structured'
            echo "Text: {$result['text']}\n";

            // For structured responses
            if ($result['type'] === 'structured') {
                print_r($result['structured']);
            }

            // Check usage
            echo "Tokens used: {$result['usage']['totalTokens']}\n";

            // Check if cached content was used
            if (isset($result['usage']['cacheReadInputTokens'])) {
                echo "Cached tokens: {$result['usage']['cacheReadInputTokens']}\n";
            }
        } else {
            echo "Request {$key} failed:\n";
            echo "Error: {$result['error']['message']}\n";
        }
    }
}
```

The parsed results contain:

- `success`: Whether the request succeeded
- `type`: Either `'text'` or `'structured'`
- `text`: The generated text content
- `structured`: Parsed JSON object (only for structured requests)
- `finishReason`: Why the generation stopped (e.g., `'STOP'`, `'MAX_TOKENS'`)
- `usage`: Token usage information
  - `promptTokens`: Input tokens
  - `completionTokens`: Output tokens
  - `totalTokens`: Total tokens
  - `cacheReadInputTokens`: Tokens read from cache (if caching was used)
  - `thoughtTokens`: Tokens used for thinking (if applicable)
- `meta`: Additional metadata (e.g., response ID)
- `error`: Error information (only if `success` is `false`)

Example with batch keys:

```php
$requests = [
    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is the capital of France?')
        ->withBatchKey('france')
        ->toRequest(),

    Prism::text()
        ->using(Provider::Gemini, 'gemini-1.5-flash')
        ->withPrompt('What is the capital of Spain?')
        ->withBatchKey('spain')
        ->toRequest(),
];

$batch = $provider->createBatchInline('gemini-1.5-flash', $requests);

// ... wait for completion ...

$results = $provider->getBatchResults($batch->outputUri);

// Access results by key
echo $results['france']['text'];  // "Paris is the capital of France."
echo $results['spain']['text'];   // "Madrid is the capital of Spain."
```

### Batch API Best Practices

1. **Use caching for repeated context**: When multiple requests share the same large context (documents, system prompts), create a cached content object first.

2. **Batch size**: There's no hard limit, but consider breaking very large batches (1000+ requests) into smaller batches for better monitoring.

3. **Monitor job status**: Batch jobs are asynchronous. Poll the job status periodically to know when results are ready.

4. **Cost optimization**: Combining batching (50% discount) with caching can reduce costs by 80-90% for certain workloads.

5. **File-based batches**: For batches with thousands of requests, use file-based batching instead of inline requests.

## Embeddings

You can customize your Gemini embeddings request with additional parameters using `->withProviderOptions()`.

### Title

You can add a title to your embedding request. Only applicable when TaskType is `RETRIEVAL_DOCUMENT`

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::embeddings()
    ->using(Provider::Gemini, 'text-embedding-004')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['title' => 'Restaurant Review'])
    ->asEmbeddings();
```

### Task Type

Gemini allows you to specify the task type for your embeddings to optimize them for specific use cases:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::embeddings()
    ->using(Provider::Gemini, 'text-embedding-004')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['taskType' => 'RETRIEVAL_QUERY'])
    ->asEmbeddings();
```

[Available task types](https://ai.google.dev/api/embeddings#tasktype)

### Output Dimensionality

You can control the dimensionality of your embeddings:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::embeddings()
    ->using(Provider::Gemini, 'text-embedding-004')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['outputDimensionality' => 768])
    ->asEmbeddings();
```

### Thinking Mode

Gemini 2.5 series models use an internal "thinking process" during response generation. Thinking is on by default as these models have the ability to automatically decide when and how much to think based on the prompt. If you would like to customize how many tokens the model may use for thinking, or disable thinking altogether, utilize the `withProviderOptions()` method, and pass through an array with a key value pair with `thinkingBudget` and an integer representing the budget of tokens. Set this value to `0` to disable thinking.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash-preview')
    ->withPrompt('Explain the concept of Occam\'s Razor and provide a simple, everyday example.')
    // Set thinking budget
    ->withProviderOptions(['thinkingBudget' => 300])
    ->asText();
```

> [!NOTE]
> Do not specify a `thinkingBudget` on 2.0 or prior series Gemini models as your request will fail.

## Streaming

Gemini supports streaming responses in real-time. All the standard streaming methods work with Gemini models:

```php
return Prism::text()
    ->using('gemini', 'gemini-2.5-flash-preview')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Streaming with Thinking

Models with thinking capabilities stream their reasoning process separately:

```php
use Prism\Prism\Enums\StreamEventType;

foreach ($stream as $event) {
    match ($event->type()) {
        StreamEventType::ThinkingDelta => echo "[Thinking] " . $event->delta,
        StreamEventType::TextDelta => echo $event->delta,
        default => null,
    };
}
```

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Media Support

Gemini has robust support for processing multimedia content:

### Video Analysis

Gemini can process and analyze video content including standard video files and YouTube videos. Prism implements this through the `Video` value object which maps to Gemini's video processing capabilities.

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([
        new UserMessage(
            'What is happening in this video?',
            additionalContent: [
                Video::fromUrl('https://example.com/sample-video.mp4'),
            ],
        ),
    ])
    ->asText();
```

### YouTube Integration

Gemini has special support for YouTube videos. You can easily `analyze/summarize` YouTube content by providing the URL:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([
        new UserMessage(
            'Summarize this YouTube video:',
            additionalContent: [
                Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
            ],
        ),
    ])
    ->asText();
```

### Audio Processing

Gemini can analyze audio files for various tasks like transcription, content analysis, and audio scene understanding. The implementation in Prism uses the `Audio` value object which is specifically designed for Gemini's audio processing capabilities.

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([
        new UserMessage(
            'Transcribe this audio file:',
            additionalContent: [
                Audio::fromLocalPath('/path/to/audio.mp3'),
            ],
        ),
    ])
    ->asText();
```

## Image Generation

Prism supports Gemini image generation through Imagen and Gemini models. See Gemini [image generation docs](https://ai.google.dev/gemini-api/docs/image-generation) for full usage.

### Supported Models

| Model                                       | Description                                        |
| ------------------------------------------- | -------------------------------------------------- |
| `gemini-2.0-flash-preview-image-generation` | Experimental gemini image generation model.        |
| `imagen-4.0-generate-001`                   | Latest Imagen model. Good for HD image generation. |
| `imagen-4.0-ultra-generate-001`             | Highest quality images, only one image per request |
| `imagen-4.0-fast-generate-001`              | Fastest Imagen 4 model                             |
| `imagen-3.0-generate-002`                   | Imagen 3                                           |

### Basic Usage

```php
$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
    ->withPrompt('Generate an image of ducklings wearing rubber boots')
    ->generate();

file_put_contents('image.png', base64_decode($response->firstImage()->base64));

// gemini models return usage and metadata
echo $response->usage->promptTokens;
echo $response->meta->id;
```

### Image Editing with Gemini

```php
$originalImage = fopen('image/boots.png', 'r');

$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
    ->withPrompt('Actually, could we make those boots red?')
    ->withProviderOptions([
        'image' => $originalImage,
        'image_mime_type' => 'image/png',
    ])
    ->generate();

file_put_contents('new-boots.png', base64_decode($response->firstImage()->base64));
```

### Image options for Imagen models

```php
$response = Prism::image()
    ->using(Provider::Gemini, 'imagen-4.0-generate-001')
    ->withPrompt('Generate an image of a magnificent building falling into the ocean')
    ->withProviderOptions([
        'n' => 3,                               // number of images to generate
        'size' => '2K',                         // 1K (default), 2K
        'aspect_ratio' => '16:9',               // 1:1 (default), 3:4, 4:3, 9:16, 16:9
        'person_generation' => 'dont_allow',    // dont_allow, allow_adult, allow_all
    ])
    ->generate();
```

Note:

- Imagen 4 Ultra can only generate 1 image at a time.
- An empty response is sent if the prompt is in violation of the person_generation policy, causing Prism to throw an Exception.

### Response Format

All generated images are returned as base64 encoded strings.
