<?php

namespace App\Services\Kafka;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publishes messages to Kafka via Redpanda's REST Proxy.
 * No PHP extension required — plain HTTP.
 *
 * Redpanda REST Proxy docs:
 *   POST /topics/{topic}
 *   Content-Type: application/vnd.kafka.json.v2+json
 */
class KafkaProducer
{
    private string $baseUrl;

    public function __construct()
    {
        // Redpanda REST Proxy — external port 18082
        $this->baseUrl = rtrim(config('kafka.rest_proxy_url', 'http://localhost:18082'), '/');
    }

    /**
     * Publish a JSON-serialisable payload to a Kafka topic.
     *
     * @param  string  $topic   e.g. 'milk.purchase.approve'
     * @param  array   $payload
     * @param  string|null $key  optional partition key
     */
    public function publish(string $topic, array $payload, ?string $key = null): void
    {
        $record = ['value' => $payload];
        if ($key !== null) {
            $record['key'] = $key;
        }

        try {
            Http::withHeaders(['Content-Type' => 'application/vnd.kafka.json.v2+json'])
                ->timeout(5)
                ->post("{$this->baseUrl}/topics/{$topic}", ['records' => [$record]])
                ->throw();
        } catch (RequestException $e) {
            Log::error('Kafka publish failed', [
                'topic'   => $topic,
                'payload' => $payload,
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
