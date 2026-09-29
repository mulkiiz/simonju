<?php
// Run: php tests/sinta_history_test.php (no network or database mutations).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/sinta_history.php';
ini_set('display_errors', '1');
$checks = 0;
function check_history($ok, $message) {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
function rejects_history($callback, $message) {
    try { $callback(); } catch (RuntimeException $e) { check_history(true, $message); return; }
    check_history(false, $message);
}
$list = '<html><body>51 Total Journals in Universitas Jenderal Soedirman
<div>Page 1 of 6 | Total Records 51</div>
<div class="list-item row"><div class="affil-name"><a href="https://sinta.kemdiktisaintek.go.id/journals/profile/828">Molekul</a></div>
<a href="https://sinta.kemdiktisaintek.go.id/affiliations/profile/7">Universitas Jenderal Soedirman</a><span>S1 <span>Accredited</span></span></div>
</body></html>';
$parsed = sinta_history_parse_list($list, 1);
check_history($parsed['pages'] === 6 && $parsed['total'] === 51 && $parsed['journals'][828]['rank'] === 1, 'list pagination, identity and rank');
rejects_history(function () use ($list) { sinta_history_parse_list($list, 2); }, 'reject repeated/wrong page');
rejects_history(function () use ($list) { sinta_history_parse_list(str_replace('/affiliations/profile/7', '/affiliations/profile/71', $list), 1); }, 'reject different affiliation');
rejects_history(function () { sinta_history_parse_list('<h1>403 Forbidden</h1>', 1); }, 'reject error page');
$prefix = '<html><body><div class="univ-name"><h3>Molekul: Jurnal Ilmiah Kimia</h3>
<div class="meta-profile"><a class="affil-loc">Universitas Jenderal Soedirman</a><a>P-ISSN : 19079761 E-ISSN : 25030310</a></div></div>
<div><div>Sinta 1</div><div>Current Acreditation</div></div>';
$table = '<p><small>History Accreditation</small></p><div><table><tr>';
foreach (range(2018, 2025) as $year) $table .= '<td><small>' . $year . '</small></td>';
$table .= '</tr><tr><td class="bg-s2" title="Sinta 2">&nbsp;</td>';
foreach (range(2019, 2025) as $year) $table .= '<td class="bg-s1" data-original-title="Sinta 1">&nbsp;</td>';
$table .= '</tr></table></div>';
$profile = $prefix . $table . '<div class="ar-year">2026</div><table><tr><td>1999</td></tr></table></body></html>';
$parsed = sinta_history_parse_profile($profile);
check_history(array_keys($parsed['years']) === range(2018, 2025), 'Molekul 2018–2025; exclude article years and other tables');
check_history($parsed['years'][2018] === 2 && $parsed['years'][2025] === 1, 'history ranks from title/class, independent of current rank');
check_history($parsed['p_issn'] === '19079761' && $parsed['e_issn'] === '25030310', 'ISSNs');
$gaps = sinta_history_parse_profile($prefix . '<small>History Accreditation</small><table><tr><td>2018</td><td>2020</td></tr><tr><td title="Sinta 3"></td><td></td></tr></table>');
check_history(array_keys($gaps['years']) === [2018, 2020] && $gaps['years'][2020] === null, 'preserve gaps and unknown ranks');
rejects_history(function () use ($prefix) { sinta_history_parse_profile($prefix); }, 'truncated profile must fail');
$noHistory = sinta_history_parse_profile($prefix . '<ul class="article-tab"><li>Garuda</li></ul>');
check_history($noHistory['years'] === [] && $noHistory['rank'] === 1, 'complete accredited profile can lack history (Agrin)');
$empty = sinta_history_parse_profile(str_replace('Sinta 1', 'Not Accredited', $prefix) . '<ul class="article-tab"><li>Garuda</li></ul>');
check_history($empty['years'] === [] && $empty['rank'] === null, 'valid unaccredited profile without history');
rejects_history(function () use ($prefix) { sinta_history_parse_profile($prefix . '<small>History Accreditation</small><table><tr><td>2018</td></tr></table>'); }, 'malformed history fails');
rejects_history(function () { sinta_history_parse_profile('<h1>Login</h1>'); }, 'login/error response is not empty history');
rejects_history(function () { sinta_history_fetch('https://example.com/journals/profile/828'); }, 'reject arbitrary fetch hosts');
echo "OK: $checks checks\n";
