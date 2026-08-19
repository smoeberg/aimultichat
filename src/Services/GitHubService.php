<?php
declare(strict_types=1);

namespace Services;

use Core\Logger;

final class GitHubService
{
    private const ALLOWED_EXTENSIONS = '/\.(md|php|js|json|ts|py|html|css|sql|yml|yaml)$/i';
    private const SENSITIVE_PATHS = '/(^|\/)(\.env(?:\.|$)|\.git\/|\.npmrc$|\.pypirc$|\.netrc$|node_modules\/|vendor\/|storage\/logs\/|secrets?\/|credentials?\/|id_rsa|id_ed25519|wp-config\.php$|.*\.(?:pem|key|p12|pfx|keystore|kdbx)$)/i';

    public function repositoryFullName(string $repoUrl): string
    {
        $parsed = parse_url(trim($repoUrl));
        $host = strtolower((string)($parsed['host'] ?? ''));
        $path = trim((string)($parsed['path'] ?? ''), '/');
        if (strtolower((string)($parsed['scheme'] ?? '')) !== 'https'
            || $host !== 'github.com'
            || isset($parsed['user'])
            || isset($parsed['pass'])) {
            throw new \InvalidArgumentException('Repository-URL skal være en almindelig https://github.com/ejer/repository URL.');
        }

        $parts = explode('/', $path);
        $owner = $parts[0] ?? '';
        $repo = preg_replace('/\.git$/i', '', $parts[1] ?? '') ?? '';
        if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $owner)
            || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $repo)
            || in_array($owner, ['.', '..'], true)
            || in_array($repo, ['.', '..'], true)) {
            throw new \InvalidArgumentException('Ugyldig GitHub repository-URL.');
        }

        return $owner . '/' . $repo;
    }

    public function isAllowedRepository(string $repository, array $allowedRepositories): bool
    {
        $repository = strtolower($repository);
        $normalized = array_map(
            static fn(string $value): string => strtolower(trim($value)),
            array_filter($allowedRepositories, 'is_string')
        );
        return in_array($repository, $normalized, true);
    }

    public function fetchRepositoryContext(string $repoUrl, string $token, array $allowedRepositories): string
    {
        $repository = $this->repositoryFullName($repoUrl);
        if (!$this->isAllowedRepository($repository, $allowedRepositories)) {
            throw new \RuntimeException('Dette repository er ikke godkendt af administratoren.', 403);
        }

        [$owner, $repo] = explode('/', $repository, 2);
        $headers = $this->headers($token);
        $base = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
        $meta = $this->requestJson($base, $headers);
        $defaultBranch = trim((string)($meta['default_branch'] ?? 'main')) ?: 'main';
        $tree = $this->requestJson(
            $base . '/git/trees/' . rawurlencode($defaultBranch) . '?recursive=1',
            $headers
        );

        $items = $tree['tree'] ?? [];
        if (!is_array($items)) {
            throw new \RuntimeException('GitHub returnerede en ugyldig filstruktur.', 502);
        }

        $fileList = [];
        $filesToRead = [];
        $maxFiles = max(1, min(20, (int)(\configValue('GITHUB_CONTEXT_MAX_FILES', '10') ?? 10)));
        foreach ($items as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'blob') {
                continue;
            }
            $filePath = (string)($item['path'] ?? '');
            if ($filePath === '' || preg_match(self::SENSITIVE_PATHS, $filePath)) {
                continue;
            }

            $fileList[] = $filePath;
            if (count($filesToRead) < $maxFiles && preg_match(self::ALLOWED_EXTENSIONS, $filePath)) {
                $filesToRead[] = $filePath;
            }
        }

        $context = "=== GITHUB REPOSITORY CONTEXT ({$repository}) ===\n";
        $context .= "Default branch: {$defaultBranch}\n";
        $context .= "Filstruktur:\n";
        $context .= $fileList === []
            ? "(ingen godkendte filer fundet)\n\n"
            : '- ' . implode("\n- ", array_slice($fileList, 0, 100)) . "\n\n";

        $maxFileBytes = max(500, min(10000, (int)(\configValue('GITHUB_CONTEXT_FILE_BYTES', '3000') ?? 3000)));
        $maxContextBytes = max(5000, min(100000, (int)(\configValue('GITHUB_CONTEXT_MAX_BYTES', '40000') ?? 40000)));
        foreach ($filesToRead as $filePath) {
            $file = $this->requestJson(
                $base . '/contents/' . str_replace('%2F', '/', rawurlencode($filePath))
                    . '?ref=' . rawurlencode($defaultBranch),
                $headers
            );
            if (($file['encoding'] ?? '') !== 'base64' || !is_string($file['content'] ?? null)) {
                continue;
            }

            $decoded = base64_decode(str_replace(["\r", "\n"], '', $file['content']), true);
            if (!is_string($decoded) || $decoded === '') {
                continue;
            }
            $content = $this->redactSecrets(mb_strcut($decoded, 0, $maxFileBytes, 'UTF-8'));
            $next = "--- FIL: {$filePath} ---\n{$content}\n\n";
            if (strlen($context) + strlen($next) > $maxContextBytes) {
                $context .= "[Repository-kontekst afkortet]\n";
                break;
            }
            $context .= $next;
        }

        return $context;
    }

    public function redactSecrets(string $content): string
    {
        $content = preg_replace(
            '/-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----/s',
            '[REDACTED PRIVATE KEY]',
            $content
        ) ?? $content;
        $content = preg_replace(
            '/(?im)^([\t ]*["\']?(?:api[_-]?key|token|password|passwd|secret|authorization)["\']?[\t ]*[:=][\t ]*).+$/',
            '$1[REDACTED]',
            $content
        ) ?? $content;
        $content = preg_replace(
            '/\b(?:github_pat_[A-Za-z0-9_]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16})\b/',
            '[REDACTED TOKEN]',
            $content
        ) ?? $content;
        return $content;
    }

    private function headers(string $token): array
    {
        $headers = [
            'User-Agent: MultiChat-App/2.0',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if (trim($token) !== '') {
            if (str_contains($token, "\r") || str_contains($token, "\n")) {
                throw new \InvalidArgumentException('GitHub-tokenet indeholder ugyldige tegn.');
            }
            $headers[] = 'Authorization: Bearer ' . trim($token);
        }
        return $headers;
    }

    private function requestJson(string $url, array $headers): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('GitHub-forbindelsen kunne ikke initialiseres.', 502);
        }
        $raw = '';
        $tooLarge = false;
        $maxBytes = max(100000, min(
            26214400,
            (int)(\configValue('GITHUB_API_MAX_RESPONSE_BYTES', '10485760') ?? 10485760)
        ));
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw, &$tooLarge, $maxBytes): int {
                if (strlen($raw) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errorNumber = curl_errno($curl);
        curl_close($curl);

        if ($tooLarge) {
            throw new \RuntimeException('GitHub-responsen overskred den tilladte størrelse.', 502);
        }
        if ($ok === false || $errorNumber !== 0) {
            Logger::warning('GitHub connection failed', ['curl_error_number' => $errorNumber]);
            throw new \RuntimeException('Kunne ikke forbinde sikkert til GitHub.', 502);
        }
        if ($status < 200 || $status >= 300) {
            Logger::warning('GitHub returned HTTP error', ['github_status' => $status]);
            throw new \RuntimeException('GitHub afviste repository-anmodningen.', $status === 404 ? 404 : 502);
        }

        try {
            $decoded = json_decode((string)$raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('GitHub returnerede ugyldig JSON.', 502, $exception);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('GitHub returnerede et ukendt svarformat.', 502);
        }
        return $decoded;
    }
}
