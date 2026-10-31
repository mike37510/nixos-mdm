<?php
declare(strict_types=1);

namespace NixVm;

use RuntimeException;

/**
 * Recherche dans l'index Elasticsearch public de search.nixos.org.
 */
final class PackageSearch
{
    private const ALIAS_TTL = 6 * 3600;

    /** @var array<string, mixed> */
    private array $cfg;

    public function __construct()
    {
        $this->cfg = app_config()['search'];
    }

    /**
     * @return list<array{attr: string, version: string, description: string, unfree: bool}>
     */
    public function search(string $query, string $release, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 80) {
            return [];
        }
        $body = [
            'size' => $limit,
            '_source' => ['package_attr_name', 'package_pversion', 'package_description', 'package_license_set'],
            'query' => [
                'bool' => [
                    'filter' => [
                        ['term' => ['type' => 'package']],
                        ['term' => ['package_system' => 'x86_64-linux']],
                    ],
                    'should' => [
                        ['term' => ['package_attr_name' => ['value' => $query, 'boost' => 100]]],
                        ['prefix' => ['package_attr_name' => ['value' => $query, 'boost' => 20]]],
                        ['term' => ['package_programs' => ['value' => $query, 'boost' => 10]]],
                    ],
                    'must' => [[
                        'multi_match' => [
                            'query' => $query,
                            'type' => 'best_fields',
                            'fuzziness' => 'AUTO',
                            'fields' => ['package_attr_name^9', 'package_pname^6', 'package_programs^4', 'package_description^1'],
                        ],
                    ]],
                ],
            ],
        ];
        $res = $this->request('/' . $this->indexFor($release) . '/_search', $body);
        $out = [];
        foreach ($res['hits']['hits'] ?? [] as $hit) {
            $src = $hit['_source'];
            if (!Catalog::isValidAttr((string) $src['package_attr_name'])) {
                continue;
            }
            $out[] = [
                'attr' => (string) $src['package_attr_name'],
                'version' => (string) ($src['package_pversion'] ?? ''),
                'description' => (string) ($src['package_description'] ?? ''),
                'unfree' => in_array('unfree', (array) ($src['package_license_set'] ?? []), true),
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $attrs
     * @return list<string> attributs introuvables dans le canal
     */
    public function missing(array $attrs, string $release): array
    {
        $found = array_column($this->resolveMany($attrs, $release), 'attr');
        return array_values(array_diff($attrs, $found));
    }

    /**
     * Résout version / description pour une liste d’attributs nixpkgs.
     *
     * @param list<string> $attrs
     * @return array<string, array{attr: string, version: string, description: string, unfree: bool}>
     */
    public function resolveMany(array $attrs, string $release): array
    {
        $attrs = array_values(array_unique(array_filter(array_map('strval', $attrs))));
        if ($attrs === []) {
            return [];
        }
        $res = $this->request('/' . $this->indexFor($release) . '/_search', [
            'size' => max(count($attrs), 1),
            '_source' => ['package_attr_name', 'package_pversion', 'package_description', 'package_license_set'],
            'query' => ['bool' => ['filter' => [
                ['term' => ['type' => 'package']],
                ['term' => ['package_system' => 'x86_64-linux']],
                ['terms' => ['package_attr_name' => $attrs]],
            ]]],
        ]);
        $out = [];
        foreach ($res['hits']['hits'] ?? [] as $hit) {
            $src = $hit['_source'];
            $attr = (string) ($src['package_attr_name'] ?? '');
            if ($attr === '') {
                continue;
            }
            $out[$attr] = [
                'attr' => $attr,
                'version' => (string) ($src['package_pversion'] ?? ''),
                'description' => (string) ($src['package_description'] ?? ''),
                'unfree' => in_array('unfree', (array) ($src['package_license_set'] ?? []), true),
            ];
        }
        return $out;
    }

    private function indexFor(string $release): string
    {
        $channel = $release === 'unstable' ? 'unstable' : $release;
        $cache = ensure_dir(STORAGE_DIR . '/cache') . '/search-aliases.json';
        $aliases = null;
        if (is_file($cache) && filemtime($cache) > time() - self::ALIAS_TTL) {
            $aliases = json_decode((string) file_get_contents($cache), true);
        }
        if (!is_array($aliases)) {
            $raw = $this->request('/_aliases', null);
            $aliases = [];
            foreach ($raw as $index) {
                foreach (array_keys($index['aliases'] ?? []) as $alias) {
                    if (preg_match('/^latest-(\d+)-nixos-(.+)$/', $alias, $m)) {
                        if (!isset($aliases[$m[2]]) || (int) $m[1] > $aliases[$m[2]][0]) {
                            $aliases[$m[2]] = [(int) $m[1], $alias];
                        }
                    }
                }
            }
            file_put_contents($cache, json_encode($aliases), LOCK_EX);
        }
        if (!isset($aliases[$channel])) {
            $channel = 'unstable';
        }
        return $aliases[$channel][1] ?? throw new RuntimeException('Index de recherche NixOS introuvable.');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $path, ?array $body): array
    {
        $ch = curl_init(rtrim($this->cfg['endpoint'], '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->cfg['timeout'],
            CURLOPT_USERPWD => $this->cfg['user'] . ':' . $this->cfg['password'],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            throw new RuntimeException('search.nixos.org indisponible' . ($err !== '' ? " : $err" : " (HTTP $code)"));
        }
        return json_decode((string) $resp, true) ?: [];
    }
}
