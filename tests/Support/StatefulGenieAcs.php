<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Simulator GenieACS NBI untuk test: menyimpan dokumen device di memori dan
 * benar-benar menjalankan addObject / setParameterValues / deleteObject padanya.
 */
class StatefulGenieAcs
{
    public array $doc;

    /** @var list<array> */
    public array $tasks = [];

    public bool $online = true;

    /** Penomoran instance AddObject oleh ONT: 'max' = terbesar+1, 'lowest' = nomor kosong terkecil. */
    public string $addObjectNumbering = 'max';

    /** Nama task yang dibuat fault (mis. 'setParameterValues'). */
    public ?string $faultOn = null;

    /** Fault untuk setParameterValues yang memuat path berakhiran ini (mis. 'Lan2Enable'). */
    public ?string $faultOnPathSuffix = null;

    /** Parameter bawaan untuk objek baru, per nama objek (mis. 'WANPPPConnection' => [...]), seperti ONT asli. */
    public array $newObjectParams = [];

    /** Parameter yang "diabaikan" ONT (nilai tidak berubah) untuk menguji verifikasi. */
    public array $ignorePaths = [];

    public function __construct(array $doc)
    {
        $this->doc = $doc;
    }

    public function fake(): self
    {
        Http::fake(fn (Request $r) => $this->handle($r));

        return $this;
    }

    private function handle(Request $r)
    {
        $path = parse_url($r->url(), PHP_URL_PATH);
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        if ($r->method() === 'GET' && $path === '/devices/') {
            $query = json_decode($q['query'] ?? '{}', true);
            $match = isset($query['_id']) ? $query['_id'] === $this->doc['_id'] : true;

            return Http::response($match ? [$this->doc] : [], 200);
        }

        if ($r->method() === 'POST' && preg_match('#^/devices/[^/]+/tasks$#', $path)) {
            $task = $r->data();
            $this->tasks[] = $task;
            $id = 'task'.count($this->tasks);

            if (! $this->online) {
                $this->pending[$id] = true;

                return Http::response(['_id' => $id] + $task, 202);
            }
            $faultPath = $this->faultOnPathSuffix !== null && $task['name'] === 'setParameterValues'
                && collect($task['parameterValues'])->contains(fn ($v) => str_ends_with($v[0], $this->faultOnPathSuffix));
            if ($this->faultOn === $task['name'] || $faultPath) {
                $this->pending[$id] = 'fault';

                return Http::response(['_id' => $id] + $task, 202);
            }
            $this->apply($task);

            return Http::response(['_id' => $id] + $task, 200);
        }

        if (in_array($r->method(), ['POST', 'DELETE'], true) && preg_match('#^/devices/[^/]+/tags/(.+)$#', $path, $m)) {
            $tag = rawurldecode($m[1]);
            $tags = array_values(array_diff($this->doc['_tags'] ?? [], [$tag]));
            $this->doc['_tags'] = $r->method() === 'POST' ? [...$tags, $tag] : $tags;

            return Http::response('', 200);
        }

        if ($r->method() === 'GET' && $path === '/tasks/') {
            $id = json_decode($q['query'], true)['_id'];

            return Http::response(isset($this->pending[$id]) ? [['_id' => $id]] : [], 200);
        }

        if ($r->method() === 'GET' && $path === '/faults/') {
            $id = json_decode($q['query'], true)['_id'];
            $taskId = substr($id, strrpos($id, 'task_') + 5);

            return Http::response(($this->pending[$taskId] ?? null) === 'fault'
                ? [['_id' => $id, 'code' => 'cwmp.9007', 'message' => 'Invalid parameter value']] : [], 200);
        }

        if ($r->method() === 'DELETE' && str_starts_with($path, '/tasks/')) {
            unset($this->pending[substr($path, 7)]);

            return Http::response('', 200);
        }

        return Http::response(['message' => 'unhandled '.$r->method().' '.$path], 500);
    }

    public array $pending = [];

    private function apply(array $task): void
    {
        switch ($task['name']) {
            case 'addObject':
                $node = &$this->node($task['objectName']);
                $ids = array_filter(array_keys($node), 'is_numeric');
                $next = $ids ? max($ids) + 1 : 1;
                if ($this->addObjectNumbering === 'lowest') {
                    for ($next = 1; in_array((string) $next, array_map('strval', $ids), true); $next++);
                }
                $last = substr($task['objectName'], strrpos($task['objectName'], '.') + 1);
                $node[(string) $next] = ['_object' => true, '_writable' => true] + ($this->newObjectParams[$last] ?? []);
                break;
            case 'deleteObject':
                $parts = explode('.', $task['objectName']);
                $last = array_pop($parts);
                $parent = &$this->node(implode('.', $parts));
                unset($parent[$last]);
                break;
            case 'setParameterValues':
                foreach ($task['parameterValues'] as [$p, $v, $t]) {
                    if (in_array($p, $this->ignorePaths, true)) {
                        continue;
                    }
                    $leaf = &$this->node($p);
                    $leaf = ['_value' => [$v, $t], '_type' => $t, '_writable' => true];
                    unset($leaf);
                }
                break;
        }
    }

    private function &node(string $path): mixed
    {
        $n = &$this->doc;
        foreach (explode('.', $path) as $seg) {
            if (! isset($n[$seg]) || ! is_array($n[$seg])) {
                $n[$seg] = [];
            }
            $n = &$n[$seg];
        }

        return $n;
    }
}
