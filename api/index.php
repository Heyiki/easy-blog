<?php

/**
 * Easy Blog (EB) Api file
 *
 * support：notion api + php + vercel + github
 *
 * notion docs
 * https://developers.notion.com/reference
 *
 * vercel
 * https://vercel.com/
 *
 *
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Token');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

final class Index
{
    private string $token;
    private string $databaseId;
    private string $aesKey;
    private string $aesIv;
    private string $method;
    private string $pageId;
    private int $pageSize = 20;
    private string $page;
    private bool $isBackend;
    private string $operateKey;

    public function __construct()
    {
        $this->token = $this->getEnv('NOTION_TOKEN');
        $this->databaseId = $this->getEnv('DATABASE_ID');
        $this->aesKey = $this->getEnv('AES_KEY');
        $this->aesIv = $this->getEnv('AES_IV');
        $this->operateKey = $this->getEnv('OPERATE_KEY');

        if (
            $this->token === '' ||
            $this->databaseId === '' ||
            $this->aesKey === '' ||
            $this->aesIv === '' ||
            $this->operateKey === ''
        ) {
            $this->retJson([], 'Missing required environment variables', 500);
        }

        // AES-256-CBC requires a 32-byte key and a 16-byte IV.
        if (strlen($this->aesKey) !== 32 || strlen($this->aesIv) !== 16) {
            $this->retJson([], 'AES_KEY must be 32 bytes and AES_IV must be 16 bytes', 500);
        }

        $this->method = strtolower($this->getParam('m'));

        if (in_array($this->method, ['create', 'edit', 'delete'], true)) {
            $this->verifyToken();
        }

        $this->pageId = $this->getParam('pid');
        $this->pageSize = max(1, min(100, (int)($this->getParam('size') ?: 20)));
        $this->page = $this->getParam('current');
        $this->isBackend = filter_var(
            $this->getParam('backend'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    private function getEnv(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : $default;
    }

    public function handle(): void
    {
        $allowedMethods = [
            'rows',
            'detail',
            'create',
            'edit',
            'delete',
            'login',
            'poetry',
            'getimg',
            'weather',
        ];

        if (!in_array($this->method, $allowedMethods, true)) {
            $this->retJson([], 'Method does not exist or is not allowed', 400);
        }

        $this->{$this->method}();
    }

    /**
     * Read a request parameter without HTML-encoding API data.
     */
    private function getParam(string $param): string
    {
        $value = $_REQUEST[$param] ?? '';

        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string)$value);
    }

    /**
     * List
     */
    public function rows(): void
    {
        $params = $this->filterCriteria();

        $res = $this->curlSend(
            'https://api.notion.com/v1/databases/' . rawurlencode($this->databaseId) . '/query',
            $params
        );

        $list = [];

        foreach (($res['results'] ?? []) as $item) {
            $properties = $item['properties'] ?? [];
            $title = $properties['title']['title'][0]['plain_text'] ?? '';

            $list[] = [
                'id' => $item['id'] ?? '',
                'created_at' => $this->isoDateToDateTime(
                    $properties['created_at']['created_time'] ?? ''
                ),
                'updated_at' => $this->isoDateToDateTime(
                    $properties['updated_at']['last_edited_time'] ?? ''
                ),
                'status' => $properties['status']['number'] ?? null,
                'tags' => $properties['tags']['rich_text'][0]['plain_text'] ?? '',
                'title' => mb_strlen($title, 'UTF-8') > 100
                    ? mb_substr($title, 0, 100, 'UTF-8') . '...'
                    : $title,
            ];
        }

        $this->retJson([
            'next_cursor' => $res['next_cursor'] ?? '',
            'list' => $list,
        ]);
    }

    /**
     * List filters
     */
    private function filterCriteria(): array
    {
        $filterParams = [
            'tf' => $this->getParam('tf'),
            'tbf' => $this->getParam('tbf'),
            'sf' => $this->getParam('sf'),
        ];

        $params = [
            'page_size' => $this->pageSize,
            'sorts' => [
                ['property' => 'updated_at', 'direction' => 'descending'],
                ['property' => 'created_at', 'direction' => 'descending'],
            ],
        ];

        if ($this->page !== '') {
            $params['start_cursor'] = $this->page;
        }

        $filter = [];

        if (!$this->isBackend) {
            $filter[] = [
                'property' => 'status',
                'number' => ['equals' => 1],
            ];
        } elseif ($filterParams['sf'] !== '' && is_numeric($filterParams['sf'])) {
            $filter[] = [
                'property' => 'status',
                'number' => ['equals' => (int)$filterParams['sf']],
            ];
        }

        if ($filterParams['tf'] !== '') {
            $filter[] = [
                'property' => 'title',
                'title' => ['contains' => $filterParams['tf']],
            ];
        }

        if ($filterParams['tbf'] !== '') {
            $filter[] = [
                'property' => 'tags',
                'rich_text' => ['contains' => $filterParams['tbf']],
            ];
        }

        if ($filter !== []) {
            $params['filter'] = ['and' => $filter];
        }

        return $params;
    }

    /**
     * Detail
     */
    public function detail(): void
    {
        if ($this->pageId === '') {
            $this->retJson([], 'Unknown object', 400);
        }

        $page = $this->curlSend(
            'https://api.notion.com/v1/pages/' . rawurlencode($this->pageId),
            [],
            'GET'
        );

        $block = $this->curlSend(
            'https://api.notion.com/v1/blocks/' . rawurlencode($this->pageId) . '/children',
            [],
            'GET'
        );

        $properties = $page['properties'] ?? [];

        if ($properties === []) {
            $this->retJson([], 'Unknown object', 404);
        }

        $content = '';

        foreach (($block['results'] ?? []) as $item) {
            $richText = $item['paragraph']['rich_text'] ?? [];

            foreach ($richText as $text) {
                $content .= $text['plain_text'] ?? '';
            }
        }

        $this->retJson([
            'pid' => $this->pageId,
            'status' => $properties['status']['number'] ?? null,
            'tags' => $properties['tags']['rich_text'][0]['plain_text'] ?? '',
            'title' => $properties['title']['title'][0]['plain_text'] ?? '',
            'content' => $content,
        ]);
    }

    /**
     * Create
     */
    public function create(): void
    {
        $title = $this->getParam('title');
        $tags = $this->getParam('tags');
        $content = $this->getParam('content');

        if ($title === '' || $tags === '' || $content === '') {
            $this->retJson([], 'Please complete this required field.', 400);
        }

        $data = [
            'parent' => [
                'type' => 'database_id',
                'database_id' => $this->databaseId,
            ],
        ];

        $data = array_merge($data, $this->structure(false));

        $res = $this->curlSend(
            'https://api.notion.com/v1/pages',
            $data
        );

        $this->retJson($res, 'Created successfully');
    }

    /**
     * Build page properties and paragraph blocks.
     */
    private function structure(bool $isEdit = false): array
    {
        $content = $this->getParam('content');
        $title = $this->getParam('title');
        $tags = $this->getParam('tags');
        $status = $this->getParam('status');

        $chunks = mb_str_split($content, 2000, 'UTF-8');
        $format = [];

        foreach ($chunks as $chunk) {
            $paragraph = [
                'rich_text' => [
                    [
                        'type' => 'text',
                        'text' => [
                            'content' => $chunk,
                        ],
                    ],
                ],
            ];

            if ($isEdit) {
                $format[] = ['paragraph' => $paragraph];
            } else {
                $format[] = [
                    'object' => 'block',
                    'type' => 'paragraph',
                    'paragraph' => $paragraph,
                ];
            }
        }

        return [
            'properties' => [
                'title' => [
                    'title' => [
                        [
                            'type' => 'text',
                            'text' => ['content' => $title],
                        ],
                    ],
                ],
                'tags' => [
                    'rich_text' => [
                        [
                            'type' => 'text',
                            'text' => ['content' => $tags],
                        ],
                    ],
                ],
                'status' => [
                    'number' => $status === '' ? 0 : (int)$status,
                ],
            ],
            'children' => $format,
        ];
    }

    /**
     * Edit
     */
    public function edit(): void
    {
        if ($this->pageId === '') {
            $this->retJson([], 'Unknown object', 400);
        }

        $title = $this->getParam('title');
        $tags = $this->getParam('tags');
        $content = $this->getParam('content');

        if ($title === '' || $tags === '' || $content === '') {
            $this->retJson([], 'Please complete this required field.', 400);
        }

        $pageId = rawurlencode($this->pageId);

        $page = $this->curlSend(
            'https://api.notion.com/v1/pages/' . $pageId,
            [],
            'GET'
        );

        if (empty($page['properties'])) {
            $this->retJson([], 'Unknown object', 404);
        }

        $block = $this->curlSend(
            'https://api.notion.com/v1/blocks/' . $pageId . '/children',
            [],
            'GET'
        );

        $data = $this->structure(true);

        // Remove existing paragraph blocks.
        foreach (($block['results'] ?? []) as $item) {
            if (!empty($item['id'])) {
                $this->curlSend(
                    'https://api.notion.com/v1/blocks/' . rawurlencode($item['id']),
                    [],
                    'DELETE'
                );
            }
        }

        // Update page properties using writable fields only.
        $res = $this->curlSend(
            'https://api.notion.com/v1/pages/' . $pageId,
            ['properties' => $data['properties']],
            'PATCH'
        );

        // Append the new paragraph blocks.
        $res1 = $this->curlSend(
            'https://api.notion.com/v1/blocks/' . $pageId . '/children',
            ['children' => $data['children']],
            'PATCH'
        );

        $this->retJson([
            'page' => $res,
            'blocks' => $res1,
        ], 'Updated successfully');
    }

    /**
     * Delete
     */
    public function delete(): void
    {
        if ($this->pageId === '') {
            $this->retJson([], 'Unknown object', 400);
        }

        $res = $this->curlSend(
            'https://api.notion.com/v1/blocks/' . rawurlencode($this->pageId),
            [],
            'DELETE'
        );

        $this->retJson($res, 'Deleted successfully');
    }

    /**
     * Send a request to Notion API.
     */
    private function curlSend(
        string $url,
        array $data = [],
        string $method = 'POST'
    ): array {
        $curl = curl_init();

        if ($curl === false) {
            $this->retJson([], 'Failed to initialize cURL', 500);
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Notion-Version: 2022-06-28',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
        ];

        if ($data !== []) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode(
                    $data,
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            } catch (JsonException $e) {
                $this->retJson([], 'Failed to encode request JSON', 500);
            }
        }

        if (!curl_setopt_array($curl, $options)) {
            $this->retJson([], 'Failed to configure cURL', 500);
        }

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // curl_close() is deprecated in PHP 8.5 and has had no effect since PHP 8.0.
        // The CurlHandle is released automatically when it is no longer referenced.
        unset($curl);

        if ($response === false) {
            $this->retJson([], 'cURL error: ' . $error, 502);
        }

        try {
            $result = json_decode(
                $response,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            $this->retJson([], 'Invalid JSON response from Notion', 502);
        }

        if (!is_array($result)) {
            $this->retJson([], 'Unexpected response from Notion', 502);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $result['message'] ?? 'Notion API request failed';

            $this->retJson(
                $result,
                (string)$message,
                $httpCode >= 400 && $httpCode <= 599 ? $httpCode : 502
            );
        }

        return $result;
    }

    /**
     * Convert an ISO 8601 date to Asia/Shanghai time.
     */
    private function isoDateToDateTime(string $isoDate): string
    {
        if ($isoDate === '') {
            return '';
        }

        try {
            $date = new DateTimeImmutable($isoDate);
            $date = $date->setTimezone(new DateTimeZone('Asia/Shanghai'));

            return $date->format('Y-m-d H:i');
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Output JSON and terminate.
     */
    private function retJson(
        array $data = [],
        string $msg = 'ok',
        int $code = 200
    ): never {
        http_response_code($code);

        try {
            echo json_encode(
                [
                    'code' => $code,
                    'msg' => $msg,
                    'data' => $data,
                ],
                JSON_UNESCAPED_UNICODE |
                JSON_INVALID_UTF8_SUBSTITUTE |
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            echo '{"code":500,"msg":"JSON encoding failed","data":[]}';
        }

        exit;
    }

    /**
     * Verify operation token.
     */
    private function verifyToken(): void
    {
        $encryptedToken = $_SERVER['HTTP_TOKEN'] ?? '';

        if (!is_string($encryptedToken) || $encryptedToken === '') {
            $this->retJson([], 'Token invalid', 403);
        }

        $decodedToken = base64_decode($encryptedToken, true);

        if ($decodedToken === false) {
            $this->retJson([], 'Token invalid', 403);
        }

        $plainToken = openssl_decrypt(
            $decodedToken,
            'AES-256-CBC',
            $this->aesKey,
            OPENSSL_RAW_DATA,
            $this->aesIv
        );

        if ($plainToken === false) {
            $this->retJson([], 'Token invalid', 403);
        }

        try {
            $data = json_decode($plainToken, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->retJson([], 'Token invalid', 403);
        }

        if (
            !is_object($data) ||
            !isset($data->exp, $data->iat, $data->key) ||
            !is_numeric($data->exp) ||
            (int)$data->exp <= time() ||
            !is_string($data->iat) ||
            !is_string($data->key) ||
            !hash_equals((string)($_SERVER['REMOTE_ADDR'] ?? ''), $data->iat) ||
            !hash_equals($this->operateKey, $data->key)
        ) {
            $this->retJson([], 'Token invalid', 403);
        }
    }

    /**
     * Login and issue an operation token.
     */
    public function login(): void
    {
        $operateKey = $this->getParam('operate_key');

        if (
            $operateKey === '' ||
            !hash_equals($this->operateKey, $operateKey)
        ) {
            $this->retJson([], 'Operation key is incorrect', 400);
        }

        try {
            $payload = json_encode(
                [
                    'exp' => time() + 86400,
                    'iat' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    'key' => $this->operateKey,
                ],
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            $this->retJson([], 'Failed to create token', 500);
        }

        $encrypted = openssl_encrypt(
            $payload,
            'AES-256-CBC',
            $this->aesKey,
            OPENSSL_RAW_DATA,
            $this->aesIv
        );

        if ($encrypted === false) {
            $this->retJson([], 'Failed to encrypt token', 500);
        }

        $this->retJson([
            'token' => base64_encode($encrypted),
        ]);
    }

    /**
     * Poetry recommendation.
     */
    public function poetry(): void
    {
        $response = $this->httpGet('https://v1.jinrishici.com/all.json');

        try {
            $data = $response !== ''
                ? json_decode($response, true, 512, JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException $e) {
            $data = [];
        }

        $this->retJson(is_array($data) ? $data : []);
    }

    public function getimg(): void
    {
        $this->retJson([
            'img' => 'https://cdn.jsdelivr.net/npm/heyiki-cdn/img/mobile-bg1.jpeg',
        ]);
    }

    /**
     * Get the next 15 days of weather data.
     */
    public function weather(): void
    {
        $url = $this->getEnv(
            'WEATHER_URL',
            'https://tianqi.moji.com/forecast15/china/guangdong/shenzhen'
        );

        if ($url === '') {
            $this->retJson([], 'Unknown location', 400);
        }

        $html = $this->httpGet($url);

        if ($html === '') {
            $this->retJson([], 'Get weather failed', 502);
        }

        $res = $this->getTagData($html, 'ul', 'class', 'clearfix');

        if ($res === []) {
            $this->retJson([], 'Get weather error location', 400);
        }

        $week = $this->getTagData($res[0], 'span', 'class', 'week');
        $temperature = $this->getTagData($res[0], 'div', 'class', 'tree clearfix');
        $weather = $this->getTagData($res[0], 'span', 'class', 'wea');

        $cycle = [];

        foreach ($week as $key => $value) {
            if ($key % 2 !== 0) {
                $cycle[$key - 1]['week'] = $value;
                continue;
            }

            $cycle[$key]['date'] = $value;

            if (isset($weather[$key], $weather[$key + 1])) {
                $cycle[$key]['wea'] = $weather[$key] === $weather[$key + 1]
                    ? $weather[$key]
                    : $weather[$key] . ' => ' . $weather[$key + 1];
            }
        }

        $cycle = array_values($cycle);

        foreach ($cycle as $key => &$item) {
            $max = [];
            $min = [];

            if (isset($temperature[$key])) {
                preg_match_all('/<b>(.*?)<\/b>/is', $temperature[$key], $max);
                preg_match_all('/<strong>(.*?)<\/strong>/is', $temperature[$key], $min);
            }

            $item['max'] = $max[1][0] ?? 'unknown';
            $item['min'] = $min[1][0] ?? 'unknown';
        }

        unset($item);

        $this->retJson($cycle);
    }

    /**
     * Fetch a URL with a timeout.
     */
    private function httpGet(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header' => "User-Agent: EasyBlog-PHP/8.5\r\n",
            ],
            'https' => [
                'timeout' => 15,
                'header' => "User-Agent: EasyBlog-PHP/8.5\r\n",
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        return $result === false ? '' : $result;
    }

    /**
     * Extract HTML elements by tag and class.
     */
    private function getTagData(
        string $html,
        string $tag,
        string $attribute,
        string $value
    ): array {
        $tag = preg_quote($tag, '/');
        $attribute = preg_quote($attribute, '/');
        $value = preg_quote($value, '/');

        $pattern = $value !== ''
            ? '/<' . $tag . '\b[^>]*\b' . $attribute . '="' . $value . '"[^>]*>(.*?)<\/' . $tag . '\s*>/is'
            : '/<' . $tag . '\b[^>]*\b' . $attribute . '="[^"]*"[^>]*>(.*?)<\/' . $tag . '\s*>/is';

        preg_match_all($pattern, $html, $matches);

        return $matches[1] ?? [];
    }
}

(new Index())->handle();
