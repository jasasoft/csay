<?php
/**
 * CleverSay Embeddings API Client
 *
 * Wraps OpenAI's embeddings API (text-embedding-3-small by default).
 * Generates 1536-dimensional vectors used for semantic similarity
 * retrieval in the Supabase vector store.
 *
 * Why OpenAI specifically (not Anthropic):
 *   - Anthropic does not offer an embeddings API
 *   - text-embedding-3-small is cheap (~$0.02 / million tokens), high
 *     quality, and well-supported by pgvector
 *   - Decoupling embeddings from synthesis lets us use different
 *     providers for each: synthesis can stay on Claude/Gemini, while
 *     embeddings go through OpenAI
 *
 * Pricing baseline (verify current rates):
 *   - text-embedding-3-small: ~$0.02 / 1M tokens
 *   - Typical chunk: 500 tokens → indexing one chunk costs ~$0.00001
 *   - Typical query: 20 tokens → query embedding costs ~$0.0000004
 *
 * @package CleverSay
 * @since   4.38.0
 */

namespace CleverSay;

if (!defined('ABSPATH')) exit;

class Embeddings {

    private const API_URL    = 'https://api.openai.com/v1/embeddings';
    private const TIMEOUT    = 30;
    private const DIMENSIONS = 1536; // text-embedding-3-small native dim

    private string $api_key;
    private string $model;
    private Logger $logger;

    public function __construct() {
        $cfg = Supabase::get_config();
        $this->api_key = (string) ($cfg['openai_api_key'] ?? '');
        $this->model   = (string) ($cfg['embedding_model'] ?? 'text-embedding-3-small');
        $this->logger  = Logger::instance();
    }

    /**
     * Whether the embeddings client is configured (has an API key).
     */
    public function is_configured(): bool {
        return $this->api_key !== '';
    }

    /**
     * Generate an embedding for a single text input.
     *
     * @param string $text  Input text. Should be reasonably short
     *                      (under ~8000 tokens — model context limit).
     * @return array|null   Array of 1536 floats on success, null on failure.
     */
    public function embed(string $text): ?array {
        if (!$this->is_configured()) {
            $this->logger->warning('Embedding requested but API key missing');
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $this->model,
                'input' => $text,
            ]),
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Embedding API request failed', [
                'error' => $response->get_error_message(),
            ]);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $this->logger->error('Embedding API returned non-200', [
                'status' => $code,
                'body'   => substr($body, 0, 500),
            ]);
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['data'][0]['embedding'])) {
            $this->logger->error('Embedding API returned malformed response', [
                'body_preview' => substr($body, 0, 200),
            ]);
            return null;
        }

        $vector = $data['data'][0]['embedding'];

        // Sanity check dimension — bail if the model returned something
        // unexpected (e.g. config drift between embedding_model setting
        // and what the API was called with).
        if (!is_array($vector) || count($vector) !== self::DIMENSIONS) {
            $this->logger->error('Embedding has unexpected dimensions', [
                'expected' => self::DIMENSIONS,
                'got'      => is_array($vector) ? count($vector) : 'non-array',
            ]);
            return null;
        }

        return $vector;
    }

    /**
     * Generate embeddings for multiple texts in a single API call.
     * The OpenAI API supports batched input which is more efficient
     * than calling embed() in a loop. Use this during bulk indexing.
     *
     * @param array $texts  Array of strings to embed.
     * @return array|null   Array of vectors (same order as input) on success,
     *                      null on failure. Returns empty array if input is empty.
     */
    public function embed_batch(array $texts): ?array {
        if (!$this->is_configured()) {
            $this->logger->warning('Batch embedding requested but API key missing');
            return null;
        }

        $texts = array_values(array_filter(
            array_map('trim', $texts),
            fn($t) => $t !== ''
        ));
        if (empty($texts)) {
            return [];
        }

        // OpenAI accepts up to 2048 inputs per call but practical batch
        // sizes are smaller. We chunk at 100 to stay safe and avoid
        // long-running requests timing out.
        $batch_size = 100;
        $all_vectors = [];

        foreach (array_chunk($texts, $batch_size) as $batch) {
            $response = wp_remote_post(self::API_URL, [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model' => $this->model,
                    'input' => $batch,
                ]),
            ]);

            if (is_wp_error($response)) {
                $this->logger->error('Batch embedding API request failed', [
                    'error' => $response->get_error_message(),
                    'batch_size' => count($batch),
                ]);
                return null;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($code !== 200) {
                $this->logger->error('Batch embedding API returned non-200', [
                    'status' => $code,
                    'body'   => substr($body, 0, 500),
                ]);
                return null;
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['data'])) {
                $this->logger->error('Batch embedding API returned malformed response', [
                    'body_preview' => substr($body, 0, 200),
                ]);
                return null;
            }

            // OpenAI returns embeddings with an 'index' field that maps
            // back to the input array. Sort by index to preserve order.
            $by_index = [];
            foreach ($data['data'] as $item) {
                if (!isset($item['index'], $item['embedding']) || !is_array($item['embedding'])) {
                    $this->logger->error('Batch embedding item malformed');
                    return null;
                }
                $by_index[$item['index']] = $item['embedding'];
            }
            ksort($by_index);
            foreach ($by_index as $vec) {
                if (count($vec) !== self::DIMENSIONS) {
                    $this->logger->error('Batch embedding has unexpected dimensions', [
                        'expected' => self::DIMENSIONS,
                        'got'      => count($vec),
                    ]);
                    return null;
                }
                $all_vectors[] = $vec;
            }
        }

        return $all_vectors;
    }

    /**
     * Test the embeddings API by generating a vector for a known input.
     * Returns a structured result for the admin "Test Connection" button.
     *
     * @return array {success: bool, message: string, details: array}
     */
    public function test(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => 'OpenAI API key not configured.',
                'details' => [],
            ];
        }

        $start = microtime(true);
        $vector = $this->embed('test query for connection check');
        $elapsed = round((microtime(true) - $start) * 1000);

        if ($vector === null) {
            return [
                'success' => false,
                'message' => 'API call failed (see logs).',
                'details' => ['elapsed_ms' => $elapsed],
            ];
        }

        return [
            'success' => true,
            'message' => 'Embedding API working.',
            'details' => [
                'model'      => $this->model,
                'dimensions' => count($vector),
                'elapsed_ms' => $elapsed,
            ],
        ];
    }
}
