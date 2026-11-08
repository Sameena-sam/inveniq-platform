<?php
// =============================================================
// InvenIQ — ML Client (the PHP <-> Python connector)
//
// Loads demand predictions from the trained model (model.pkl) two ways:
//   1. Flask service  (recommended)  : cURL GET http://127.0.0.1:5000/predict
//   2. shell_exec fallback (simplest): runs `python predict.py`
//
// Results are cached in-process AND on disk (TTL) so pages stay fast.
// Every method degrades gracefully: if Python/the model is unavailable
// the engine simply falls back to its built-in rule-based calculation.
// =============================================================
require_once __DIR__ . '/config.php';

class MLClient
{
    private string $flaskUrl;
    private string $python;
    private string $script;
    private string $cacheFile;
    private int    $ttl;
    private static ?array $memo = null;     // per-request memo

    public function __construct()
    {
        $this->flaskUrl  = defined('ML_FLASK_URL')      ? ML_FLASK_URL      : 'http://127.0.0.1:5000';
        $this->python    = defined('ML_PYTHON')         ? ML_PYTHON         : 'python';
        $this->script    = defined('ML_PREDICT_SCRIPT') ? ML_PREDICT_SCRIPT : realpath(__DIR__ . '/../ml/predict.py');
        $this->ttl       = defined('ML_CACHE_TTL')      ? ML_CACHE_TTL      : 900;   // 15 min
        $this->cacheFile = sys_get_temp_dir() . '/inveniq_ml_cache.json';
    }

    /** Full payload: ['ok'=>bool,'source'=>..,'model'=>[..],'predictions'=>[sku=>..]] */
    public function getPayload(): array
    {
        if (self::$memo !== null) return self::$memo;

        // disk cache (survives across requests)
        if (is_readable($this->cacheFile) && (time() - filemtime($this->cacheFile) < $this->ttl)) {
            $cached = json_decode((string)file_get_contents($this->cacheFile), true);
            if (is_array($cached) && !empty($cached['ok'])) {
                return self::$memo = $cached;
            }
        }

        $payload = $this->fromFlask();
        if (empty($payload['ok'])) {
            $payload = $this->fromShell();
        }

        if (!empty($payload['ok'])) {
            @file_put_contents($this->cacheFile, json_encode($payload));
        }
        return self::$memo = ($payload ?: ['ok' => false]);
    }

    /** [sku => daily_demand]  (empty array if model unavailable) */
    public function dailyDemand(): array
    {
        $p = $this->getPayload();
        if (empty($p['ok'])) return [];
        $out = [];
        foreach ($p['predictions'] as $sku => $row) {
            $out[$sku] = (float)($row['daily_demand'] ?? 0);
        }
        return $out;
    }

    public function predictions(): array
    {
        $p = $this->getPayload();
        return empty($p['ok']) ? [] : $p['predictions'];
    }

    public function modelInfo(): array
    {
        $p = $this->getPayload();
        return [
            'available' => !empty($p['ok']),
            'source'    => $p['source'] ?? 'none',
            'model'     => $p['model'] ?? null,
        ];
    }

    public function isAvailable(): bool
    {
        $p = $this->getPayload();
        return !empty($p['ok']);
    }

    /** {segment => [12 monthly seasonal-index values, Jan..Dec]}
     *  (empty array if the model is unavailable or predates this feature) */
    public function seasonalIndex(): array
    {
        $p = $this->getPayload();
        if (empty($p['ok'])) return [];
        return $p['model']['seasonal_index'] ?? [];
    }

    // ---------- transport 1: Flask ----------
    private function fromFlask(): array
    {
        if (!function_exists('curl_init')) return ['ok' => false];
        $ch = curl_init($this->flaskUrl . '/predict');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code !== 200) return ['ok' => false];
        $data = json_decode($res, true);
        if (!is_array($data) || empty($data['ok'])) return ['ok' => false];
        $data['source'] = 'flask';
        return $data;
    }

    // ---------- transport 2: shell_exec ----------
    private function fromShell(): array
    {
        if (!function_exists('shell_exec') || !$this->script) return ['ok' => false];
        $cmd = escapeshellarg($this->python) . ' ' . escapeshellarg($this->script) . ' 2>&1';
        $out = @shell_exec($cmd);
        if (!$out) return ['ok' => false];
        // predict.py prints JSON on the last non-empty line
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
        $json  = end($lines);
        $data  = json_decode((string)$json, true);
        if (!is_array($data) || empty($data['ok'])) return ['ok' => false];
        $data['source'] = 'shell';
        return $data;
    }
}
