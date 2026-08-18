<?php
declare(strict_types=1);
namespace Services;

use Core\Logger;

final class GitHubService {
    public function fetchRepositoryContext(string $repoUrl, string $token = ''): string {
        $parsed = parse_url(trim($repoUrl));
        $host = strtolower((string)($parsed['host'] ?? ''));
        $path = trim((string)($parsed['path'] ?? ''), '/');
        if ($host !== 'github.com') {
            throw new \RuntimeException('GitHub repository URL skal være på github.com.', 400);
        }

        $parts = explode('/', $path);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \RuntimeException('Ugyldigt GitHub repository URL. Format: https://github.com/ejer/repository', 400);
        }

        $owner = $parts[0];
        $repo = preg_replace('/\.git$/i', '', $parts[1]);
        $headers = $this->headers($token);

        // Hent metadata først, så vi bruger repositoryets faktiske default branch.
        $meta = $this->requestJson("https://api.github.com/repos/{$owner}/{$repo}", $headers);
        $defaultBranch = (string)($meta['default_branch'] ?? 'main');
        if ($defaultBranch === '') $defaultBranch = 'main';

        $tree = $this->requestJson(
            'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/trees/' . rawurlencode($defaultBranch) . '?recursive=1',
            $headers
        );

        $items = $tree['tree'] ?? [];
        if (!is_array($items)) {
            throw new \RuntimeException('GitHub returnerede en ugyldig filstruktur.', 502);
        }

        $fileList = [];
        $filesToRead = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'blob') continue;
            $filePath = (string)($item['path'] ?? '');
            if ($filePath === '') continue;
            $fileList[] = $filePath;
            if (count($filesToRead) < 10 && preg_match('/\.(md|php|js|json|ts|py|html|css|sql|yml|yaml)$/i', $filePath)) {
                $filesToRead[] = $filePath;
            }
        }

        $context = "=== GITHUB REPOSITORY CONTEXT ({$owner}/{$repo}) ===\n";
        $context .= "Default branch: {$defaultBranch}\n";
        $context .= "Filstruktur i repository:\n";
        $context .= $fileList === [] ? "(ingen filer fundet)\n\n" : '- ' . implode("\n- ", array_slice($fileList, 0, 50)) . "\n\n";

        foreach ($filesToRead as $filePath) {
            $rawUrl = 'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/' . rawurlencode($defaultBranch) . '/' . str_replace('%2F', '/', rawurlencode($filePath));
            [$fileContent, $fileStatus] = $this->request($rawUrl, $headers);
            if ($fileStatus === 200 && is_string($fileContent) && $fileContent !== '') {
                $shortContent = mb_substr($fileContent, 0, 3000);
                $context .= "--- FIL: {$filePath} ---\n{$shortContent}\n\n";
            }
        }

        return $context;
    }

    private function headers(string $token): array {
        $headers = [
            'User-Agent: MultiChat-App/1.0',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28'
        ];
        if (trim($token) !== '') $headers[] = 'Authorization: Bearer ' . trim($token);
        return $headers;
    }

    private function requestJson(string $url, array $headers): array {
        [$raw, $status] = $this->request($url, $headers);
        if ($raw === false || $status < 200 || $status >= 300) {
            $detail = '';
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $detail = $this->scalarError($decoded['message'] ?? ($decoded['error'] ?? ''));
                } else {
                    $detail = trim($raw);
                }
            }
            $suffix = $detail !== '' ? ': ' . $detail : '';
            throw new \RuntimeException("Kunne ikke læse GitHub repository (HTTP {$status}){$suffix}", 502);
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('GitHub returnerede ugyldig JSON.', 502);
        return $decoded;
    }

    private function request(string $url, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($raw === false && $errno !== 0) {
            throw new \RuntimeException('Kunne ikke forbinde til GitHub (cURL fejl ' . $errno . ').', 502);
        }
        return [$raw, $status];
    }

    private function scalarError(mixed $value): string {
        if (is_string($value) || is_numeric($value) || is_bool($value)) return (string)$value;
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $k => $v) {
                $part = $this->scalarError($v);
                if ($part !== '') $parts[] = is_string($k) ? $k . ': ' . $part : $part;
            }
            return implode('; ', $parts);
        }
        return '';
    }
}
