<?php
// =============================================================
// InvenIQ -- AI Client (PHP <-> Anthropic Claude connector)
//
// Sends a user question, plus a live snapshot of inventory data
// built by InventoryEngine::getAIContext(), to Claude's Messages
// API and returns its answer. Degrades gracefully: if no API key
// is set, or the call fails, the assistant explains why instead
// of crashing the page.
// =============================================================
require_once __DIR__ . '/config.php';

class AIClient
{
    private string $apiKey;
    private string $model;
    private string $endpoint = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
        $this->model  = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-3-5-haiku-20241022';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param string $question  The user's message
     * @param string $context   Live inventory snapshot (InventoryEngine::getAIContext())
     * @param array  $history   Prior turns: [['role'=>'user'|'assistant','content'=>'...'], ...]
     */
    public function ask(string $question, string $context, array $history = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok'    => false,
                'reply' => "The AI Assistant isn't connected yet -- add an ANTHROPIC_API_KEY in includes/config.php to enable it.",
            ];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'reply' => 'The AI Assistant needs the PHP cURL extension, which is not enabled on this server.'];
        }

        $system = "You are the InvenIQ AI Inventory Assistant, embedded in a pharmaceutical/FMCG "
                . "distribution company's inventory management dashboard. Answer only from the LIVE "
                . "INVENTORY SNAPSHOT below -- it is real data as of this moment. Never invent SKUs, "
                . "numbers or supplier names that are not in the snapshot. If the question needs data "
                . "that isn't in the snapshot, say so plainly and suggest which page of the app would "
                . "have it (e.g. Stock Health, Reorder Points, Supplier Performance). Keep answers short "
                . "(2-5 sentences), concrete, and business-actionable. You may use basic HTML tags "
                . "(<strong>, <br>, <a href=\"...\">) since the reply is rendered directly in a chat bubble.\n\n"
                . "LIVE INVENTORY SNAPSHOT:\n" . $context;

        $messages = [];
        foreach ($history as $turn) {
            if (in_array($turn['role'] ?? '', ['user', 'assistant'], true) && !empty($turn['content'])) {
                $messages[] = ['role' => $turn['role'], 'content' => (string) $turn['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => 400,
            'system'     => $system,
            'messages'   => $messages,
        ]);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['ok' => false, 'reply' => 'Could not reach the AI service: ' . $err];
        }
        $data = json_decode($res, true);
        if ($code !== 200 || !is_array($data)) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            return ['ok' => false, 'reply' => 'AI service error: ' . $msg];
        }

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        if ($text === '') {
            return ['ok' => false, 'reply' => 'The AI service returned an empty response.'];
        }
        return ['ok' => true, 'reply' => $text];
    }
}
