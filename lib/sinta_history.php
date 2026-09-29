<?php
/** History SINTA Unsoed. PHP 7.3; one remote page per resumable sync step. */
require_once __DIR__ . '/crawler.php';

function sinta_history_source() {
    return 'https://sinta.kemdiktisaintek.go.id/journals/index/7';
}

function sinta_history_profile_url($id) {
    return 'https://sinta.kemdiktisaintek.go.id/journals/profile/' . (int)$id;
}

/** Resolve SINTA profile IDs to the local jurnals.id used by jurnal_view.php. */
function sinta_history_local_journal_ids($records) {
    if (!$records) return [];
    $locals = fetch_all('SELECT id, nama_jurnal, p_issn, e_issn, link_sinta FROM jurnals');
    $byProfile = [];
    foreach ($locals as $local) {
        if (preg_match('~/journals/profile/(\d+)~', (string)$local['link_sinta'], $match)) {
            $byProfile[(int)$match[1]] = (int)$local['id'];
        }
    }
    $normalize = function ($value) {
        return strtoupper(preg_replace('/[^0-9X]/i', '', (string)$value));
    };
    $resolved = [];
    foreach ($records as $record) {
        $profileId = (int)$record['id'];
        if (isset($byProfile[$profileId])) {
            $resolved[$profileId] = $byProfile[$profileId];
            continue;
        }
        $p = $normalize($record['p_issn'] ?? '');
        $e = $normalize($record['e_issn'] ?? '');
        // Prefer the print ISSN; use the electronic ISSN only when no print ISSN exists.
        $needle = $p !== '' ? $p : $e;
        if ($needle === '') continue;
        $hits = [];
        foreach ($locals as $local) {
            $localIssn = $p !== '' ? $normalize($local['p_issn']) : $normalize($local['e_issn']);
            if ($localIssn === $needle) $hits[(int)$local['id']] = (int)$local['id'];
        }
        if (count($hits) === 1) $resolved[$profileId] = reset($hits);
        if (isset($resolved[$profileId])) continue;
        $normalizeName = function ($value) {
            return strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$value));
        };
        $name = $normalizeName($record['name'] ?? '');
        $nameHits = [];
        foreach ($locals as $local) {
            if ($name !== '' && $normalizeName($local['nama_jurnal'] ?? '') === $name) {
                $nameHits[(int)$local['id']] = (int)$local['id'];
            }
        }
        if (count($nameHits) === 1) $resolved[$profileId] = reset($nameHits);
    }
    return $resolved;
}

function sinta_history_text($node) {
    if (!$node) return '';
    $parts = [];
    $xp = new DOMXPath($node->ownerDocument);
    foreach ($xp->query('.//text()', $node) as $text) $parts[] = $text->nodeValue;
    return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
}

function sinta_history_xpath($html) {
    if (!class_exists('DOMDocument')) throw new RuntimeException('Ekstensi PHP DOM diperlukan.');
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$ok) throw new RuntimeException('HTML SINTA tidak dapat dibaca.');
    return new DOMXPath($doc);
}

function sinta_history_class($class) {
    return "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
}

function sinta_history_parse_list($html, $page) {
    $xp = sinta_history_xpath($html);
    $text = sinta_history_text($xp->query('//body')->item(0));
    if (!preg_match('/Page\s+(\d+)\s+of\s+(\d+)\s*\|\s*Total Records\s+([\d.,]+)/i', $text, $m)
        || (int)$m[1] !== (int)$page || (int)$m[2] < 1 || (int)$m[2] > 100) {
        throw new RuntimeException('Paginasi SINTA tidak dikenali. Sinkronisasi dihentikan agar data tidak terpotong.');
    }
    if (stripos($text, 'Universitas Jenderal Soedirman') === false) {
        throw new RuntimeException('Daftar SINTA bukan daftar Universitas Jenderal Soedirman.');
    }
    $pages = (int)$m[2];
    $total = (int)preg_replace('/\D/', '', $m[3]);
    $journals = [];
    foreach ($xp->query('//*[' . sinta_history_class('list-item') . ']') as $card) {
        $link = $xp->query('.//*[' . sinta_history_class('affil-name') . ']/a', $card)->item(0);
        if (!$link || !preg_match('~/journals/profile/(\d+)(?:[/?#]|$)~', $link->getAttribute('href'), $id)) continue;
        $publisher = $xp->query('.//a[contains(@href,"/affiliations/profile/")]', $card)->item(0);
        if (!$publisher || !preg_match('~/affiliations/profile/7/?(?:[?#].*)?$~', $publisher->getAttribute('href'))) {
            throw new RuntimeException('Afiliasi jurnal pada daftar SINTA tidak sesuai Unsoed.');
        }
        $cardText = sinta_history_text($card);
        $rank = preg_match('/\bS([1-6])\s+Accredited\b/i', $cardText, $r) ? (int)$r[1] : null;
        $journals[(int)$id[1]] = [
            'id' => (int)$id[1], 'name' => sinta_history_text($link),
            'publisher' => sinta_history_text($publisher), 'rank' => $rank,
        ];
    }
    if (!$journals || $total < count($journals)) {
        throw new RuntimeException('Daftar jurnal SINTA kosong atau strukturnya berubah. Hasil lama tetap disimpan.');
    }
    return ['journals' => $journals, 'pages' => $pages, 'total' => $total];
}

function sinta_history_parse_profile($html) {
    $xp = sinta_history_xpath($html);
    $heading = $xp->query('//*[' . sinta_history_class('univ-name') . ']/h3')->item(0);
    $current = $xp->query('//*[normalize-space(.)="Current Acreditation" or normalize-space(.)="Current Accreditation"]')->item(0);
    if (!$heading || !$current) throw new RuntimeException('Profil SINTA tidak dikenali (halaman blokir/login atau struktur berubah).');
    $rankText = sinta_history_text($current->parentNode);
    $rank = preg_match('/\bSinta\s*([1-6])\b/i', $rankText, $m) ? (int)$m[1] : null;
    $labels = $xp->query('//*[not(*) and normalize-space(.)="History Accreditation"]');
    $years = [];
    if ($labels->length) {
        $table = $xp->query('following::table[1]', $labels->item(0))->item(0);
        if (!$table) throw new RuntimeException('Tabel History Accreditation tidak ditemukan.');
        $rows = $xp->query('.//tr', $table);
        if ($rows->length < 2) throw new RuntimeException('Baris tahun/peringkat history SINTA tidak lengkap.');
        $yearCells = $xp->query('./td | ./th', $rows->item(0));
        $rankCells = $xp->query('./td | ./th', $rows->item(1));
        if (!$yearCells->length || $yearCells->length !== $rankCells->length) {
            throw new RuntimeException('Jumlah kolom tahun dan peringkat SINTA tidak sesuai.');
        }
        foreach ($yearCells as $i => $cell) {
            $year = sinta_history_text($cell);
            if (!preg_match('/^(19|20)\d{2}$/', $year)) throw new RuntimeException('Tahun history SINTA tidak dikenali.');
            $rankCell = $rankCells->item($i);
            $attributes = $rankCell->getAttribute('title') . ' ' . $rankCell->getAttribute('data-original-title') . ' ' . sinta_history_text($rankCell);
            $value = null;
            if (preg_match('/\bSinta\s*([1-6])\b/i', $attributes, $r)
                || preg_match('/\bbg-s([1-6])\b/i', $rankCell->getAttribute('class'), $r)) $value = (int)$r[1];
            if (array_key_exists((int)$year, $years)) throw new RuntimeException('Tahun history SINTA duplikat.');
            $years[(int)$year] = $value;
        }
        ksort($years, SORT_NUMERIC);
    } elseif (!$xp->query('//*[' . sinta_history_class('article-tab') . ']')->length) {
        throw new RuntimeException('Profil SINTA tidak lengkap; bagian history belum dapat diverifikasi.');
    }
    $publisher = $xp->query('//*[' . sinta_history_class('meta-profile') . ']/*[' . sinta_history_class('affil-loc') . ']')->item(0);
    $issn = sinta_history_text($xp->query('//*[' . sinta_history_class('meta-profile') . ']')->item(0));
    return [
        'name' => sinta_history_text($heading), 'publisher' => sinta_history_text($publisher),
        'rank' => $rank, 'years' => $years,
        'p_issn' => preg_match('/P-ISSN\s*:\s*([\dXx-]{8,9})/', $issn, $p) ? $p[1] : '',
        'e_issn' => preg_match('/E-ISSN\s*:\s*([\dXx-]{8,9})/', $issn, $e) ? $e[1] : '',
        'fetched_at' => date('Y-m-d H:i:s'), 'error' => '',
    ];
}

/** Only fixed public SINTA URLs; never follow an arbitrary remote redirect. */
function sinta_history_fetch($url) {
    if (!preg_match('~^https://sinta\.kemdiktisaintek\.go\.id/journals/(?:index/7(?:\?page=[1-9]\d*)?|profile/[1-9]\d*)$~D', $url)) {
        throw new RuntimeException('URL sumber SINTA tidak valid.');
    }
    if (!curl_is_available()) throw new RuntimeException('Ekstensi PHP cURL diperlukan untuk sinkronisasi SINTA.');
    $attempt = function ($ca = null) use ($url) {
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => defined('CRAWLER_TIMEOUT') ? CRAWLER_TIMEOUT : 30,
            CURLOPT_USERAGENT => crawler_ua(),
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: id,en;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        // SINTA's default Impact order contains many ties across page boundaries.
        // Use its public sort form on every list request (no shared cookie/session).
        if (strpos($url, '/journals/index/7') !== false) {
            $page = parse_url($url, PHP_URL_QUERY);
            parse_str((string)$page, $query);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['changesort' => 1, 'sort' => 4, 'page' => (int)($query['page'] ?? 1)]));
        }
        if ($ca) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Sort POST uses PRG and a short-lived SINTA cookie in this handle only.
        if ($ok && in_array($code, [302, 303], true) && strpos($url, '/journals/index/7') !== false) {
            $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            if (preg_match('~^https://sinta\.kemdiktisaintek\.go\.id/journals/index/7(?:\?page=[1-9]\d*)?$~D', $redirect)) {
                $body = '';
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                // SINTA resets to page 1 when sorting; request the desired page
                // with the newly established sort cookie instead.
                curl_setopt($ch, CURLOPT_URL, $url);
                $ok = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }
        }
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        return compact('body', 'ok', 'code', 'error', 'errno');
    };
    $res = $attempt();
    $ca = custom_ca_bundle_path();
    if ($res['errno'] === 60 && is_file($ca)) $res = $attempt($ca);
    if (!$res['ok'] || $res['code'] !== 200) {
        throw new RuntimeException('SINTA gagal diakses (HTTP ' . $res['code'] . '). ' . ($res['error'] ?: 'Coba kembali nanti.'));
    }
    return $res['body'];
}

function sinta_history_empty() {
    return ['records' => [], 'job' => null, 'completed_at' => null, 'source_total' => 0, 'errors' => []];
}

/** Ignore a duplicate SINTA profile in favor of the profile selected by PPJ. */
function sinta_history_profile_exclusions() {
    return [3809 => 9842];
}

function sinta_history_apply_profile_exclusions(&$state) {
    $exclusions = sinta_history_profile_exclusions();
    foreach ($exclusions as $excludedId => $preferredId) {
        unset($state['records'][$excludedId], $state['errors'][$excludedId]);
        if (empty($state['job']) || $state['job']['phase'] !== 'profiles') continue;

        $journals = array_values($state['job']['journals']);
        $processed = array_slice($journals, 0, (int)$state['job']['cursor']);
        $state['job']['journals'] = array_values(array_filter($journals, function ($journal) use ($excludedId) {
            return (int)$journal['id'] !== (int)$excludedId;
        }));
        $state['job']['cursor'] = count(array_filter($processed, function ($journal) use ($excludedId) {
            return (int)$journal['id'] !== (int)$excludedId;
        }));
        unset($state['job']['results'][$excludedId], $state['job']['errors'][$excludedId]);
    }
}

function sinta_history_load() {
    $exists = fetch_one("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sinta_history_state'");
    if (empty($exists['n'])) return sinta_history_empty();
    $row = fetch_one('SELECT payload FROM sinta_history_state WHERE id=1');
    if (!$row) return sinta_history_empty();
    $state = json_decode($row['payload'], true);
    if (!is_array($state) || !isset($state['records'])) throw new RuntimeException('Data history tersimpan tidak dapat dibaca.');
    sinta_history_apply_profile_exclusions($state);
    return $state;
}

function sinta_history_save($state) {
    sinta_history_apply_profile_exclusions($state);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $saved = $json === false ? false : exec_q('INSERT INTO sinta_history_state (id,payload) VALUES (1,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)', 's', [$json]);
    if ($saved === false || $saved['affected'] < 0) {
        throw new RuntimeException('Gagal menyimpan history akreditasi.');
    }
}

function sinta_history_status($state) {
    $job = $state['job'];
    return [
        'running' => $job !== null, 'phase' => $job ? $job['phase'] : 'done',
        'page' => $job ? $job['page'] : 0, 'pages' => $job ? $job['pages'] : 0,
        'list_round' => $job ? ($job['list_round'] ?? 1) : 1,
        'processed' => $job ? $job['cursor'] : count($state['records']),
        'total' => $job ? ($job['phase'] === 'list' ? $job['total'] : count($job['journals'])) : $state['source_total'],
        'errors' => count($job ? $job['errors'] : $state['errors']),
        'completed_at' => $state['completed_at'],
    ];
}

/** Advisory lock covers each read/modify/write and remote request, across admin sessions. */
function sinta_history_sync($action) {
    $lockName = 'sinta_history_' . substr(hash('sha256', DB_NAME), 0, 32);
    $lock = fetch_one('SELECT GET_LOCK(?,0) AS acquired', 's', [$lockName]);
    if (empty($lock['acquired'])) throw new RuntimeException('Sinkronisasi sedang diproses admin/tab lain. Coba lanjutkan sesaat lagi.');
    try {
        if (exec_q('CREATE TABLE IF NOT EXISTS sinta_history_state (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, payload MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') === false) {
            throw new RuntimeException('Tabel history tidak dapat dibuat. Periksa izin CREATE pada database.');
        }
        $state = sinta_history_load();
        if ($action === 'retry' && $state['job'] === null && $state['errors']) {
            $journals = [];
            foreach ($state['errors'] as $id => $message) {
                if (isset($state['records'][$id])) $journals[] = $state['records'][$id];
            }
            if ($journals) {
                $state['job'] = ['phase' => 'profiles', 'page' => 0, 'pages' => 0,
                    'total' => $state['source_total'], 'journals' => $journals, 'cursor' => 0,
                    'results' => $state['records'], 'errors' => [], 'last_request' => 0];
                sinta_history_save($state);
                return sinta_history_status($state);
            }
        }
        if ($action === 'restart' || ($action === 'start' && $state['job'] === null)) {
            $state['job'] = ['phase' => 'list', 'page' => 1, 'pages' => 0, 'total' => 0, 'list_round' => 1,
                'journals' => [], 'cursor' => 0, 'results' => [], 'errors' => [], 'last_request' => 0];
            sinta_history_save($state);
            return sinta_history_status($state);
        }
        if ($state['job'] === null) return sinta_history_status($state);
        $job = &$state['job'];
        $delay = defined('CRAWLER_DELAY_MS') ? max(0, (int)CRAWLER_DELAY_MS) : 1500;
        $remaining = $delay / 1000 - (microtime(true) - $job['last_request']);
        if ($remaining > 0) usleep((int)($remaining * 1000000));
        $job['last_request'] = microtime(true);
        sinta_history_save($state);
        if ($job['phase'] === 'list') {
            $list = sinta_history_parse_list(sinta_history_fetch(sinta_history_source() . '?page=' . $job['page']), $job['page']);
            if ($job['total'] && ($job['total'] !== $list['total'] || $job['pages'] !== $list['pages'])) {
                throw new RuntimeException('Jumlah jurnal SINTA berubah saat dibaca. Coba ulang sinkronisasi dari awal.');
            }
            $job['pages'] = $list['pages'];
            $job['total'] = $list['total'];
            foreach ($list['journals'] as $id => $journal) $job['journals'][$id] = $journal;
            if ($job['page'] >= $job['pages']) {
                if (count($job['journals']) < $job['total'] && ($job['list_round'] ?? 1) < 3) {
                    // SINTA has unstable ordering even for equal citation counts.
                    // Reconcile IDs across bounded passes, never assume one pass is complete.
                    $job['list_round'] = ($job['list_round'] ?? 1) + 1;
                    $job['page'] = 1;
                } elseif (count($job['journals']) !== $job['total']) {
                    throw new RuntimeException('Daftar SINTA belum lengkap/berubah urutan (' . count($job['journals']) . '/' . $job['total'] . '). Coba ulang sinkronisasi dari awal.');
                } else {
                    $job['journals'] = array_values($job['journals']);
                    $excluded = sinta_history_profile_exclusions();
                    $job['journals'] = array_values(array_filter($job['journals'], function ($journal) use ($excluded) {
                        return !isset($excluded[(int)$journal['id']]);
                    }));
                    $job['phase'] = 'profiles';
                }
            } else {
                $job['page']++;
            }
        } else {
            $journal = $job['journals'][$job['cursor']];
            $id = $journal['id'];
            try {
                $record = array_merge($journal, sinta_history_parse_profile(sinta_history_fetch(sinta_history_profile_url($id))));
            } catch (RuntimeException $e) {
                $job['errors'][$id] = $journal['name'] . ': ' . $e->getMessage();
                $record = $state['records'][$id] ?? array_merge($journal, ['years' => [], 'fetched_at' => null, 'p_issn' => '', 'e_issn' => '']);
                $record['error'] = $e->getMessage();
            }
            $job['results'][$id] = $record;
            $job['cursor']++;
            if ($job['cursor'] >= count($job['journals'])) {
                $state['records'] = $job['results'];
                $state['source_total'] = $job['total'];
                $state['errors'] = $job['errors'];
                $state['completed_at'] = date('Y-m-d H:i:s');
                unset($job);
                $state['job'] = null;
            }
        }
        sinta_history_save($state);
        return sinta_history_status($state);
    } finally {
        fetch_one('SELECT RELEASE_LOCK(?) AS released', 's', [$lockName]);
    }
}
