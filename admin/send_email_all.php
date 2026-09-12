<?php
/**
 * admin/send_email_all.php — Sebar akun login ke SEMUA email editor (batch).
 *
 * Kirim bertahap + jeda (throttle) via loop fetch di browser, supaya SMTP
 * tidak memblokir karena burst. JALANKAN DARI SERVER PPJ (IP datacenter),
 * bukan XAMPP lokal (IP rumah rawan diblokir provider).
 *
 * Resume: kolom jurnal_accounts.email_login_sent_at menandai yang sudah terkirim.
 */
$page_title = 'Sebar Akun Login';
require_once __DIR__ . '/../includes/header_admin.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();

// Hanya jurnal dengan email editor format wajar yang dihitung/dikirim.
const EMAIL_COND = "e.email LIKE '%@%.%'";

// Kolom penanda resume — WAJIB ada (migrasi sql_email_login_sent.sql).
$col = fetch_one("SHOW COLUMNS FROM jurnal_accounts LIKE 'email_login_sent_at'");
$has_col = !empty($col);

/* ── Endpoint aksi (AJAX, POST + CSRF) ─────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pastikan respons selalu JSON bersih (jangan biarkan warning/HTML bocor).
    @ini_set('display_errors', '0');
    error_reporting(0);
    ignore_user_abort(true);
    @set_time_limit(0);           // SMTP bisa lambat; jangan sampai fatal timeout
    while (ob_get_level() > 0) { ob_end_clean(); }

    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    if (!$has_col) {
        echo json_encode(['ok' => false, 'msg' => 'Kolom email_login_sent_at belum ada. Jalankan sql_email_login_sent.sql di phpMyAdmin server ini dulu.']);
        exit;
    }
    $act = $_POST['act'] ?? '';

    if ($act === 'reset') {
        exec_q("UPDATE jurnal_accounts SET email_login_sent_at=NULL");
        echo json_encode(['ok' => true, 'msg' => 'Status kirim direset.']);
        exit;
    }

    if ($act === 'send') {
        $limit = max(1, min(5, (int)($_POST['limit'] ?? 3)));

        // Selalu ambil yang BELUM terkirim (email_login_sent_at NULL) supaya
        // batch maju terus tanpa duplikasi. "Kirim ulang" = reset dulu.
        $rows = fetch_all(
            "SELECT ja.id, ja.username, j.nama_jurnal, j.konfirmasi_token,
                    e.email, e.nama AS nama_editor
             FROM jurnal_accounts ja
             JOIN jurnals j ON j.id = ja.jurnal_id
             LEFT JOIN editor e ON e.jurnal_id = ja.jurnal_id
             WHERE " . EMAIL_COND . " AND ja.email_login_sent_at IS NULL
             ORDER BY j.nama_jurnal
             LIMIT {$limit}"
        );

        $log = [];
        foreach ($rows as $r) {
            try {
                $subject = 'Info Login Simonju - ' . $r['nama_jurnal'];
                $body    = build_jurnal_email($r['nama_jurnal'], $r['username'], $r['konfirmasi_token'] ?? '(lihat admin)');
                [$ok, $m] = send_smtp_mail($r['email'], $r['nama_editor'] ?: $r['nama_jurnal'], $subject, $body, true);
            } catch (\Throwable $e) {
                $ok = false; $m = 'Exception: ' . $e->getMessage();
            }
            if ($ok) {
                exec_q("UPDATE jurnal_accounts SET email_login_sent_at=NOW() WHERE id=?", 'i', [(int)$r['id']]);
            }
            $log[] = ['jurnal' => $r['nama_jurnal'], 'email' => $r['email'], 'ok' => (bool)$ok, 'msg' => $m];
        }

        // Sisa yang belum terkirim (format email wajar).
        $rem = (int)(fetch_one(
            "SELECT COUNT(*) c FROM jurnal_accounts ja
             JOIN jurnals j ON j.id=ja.jurnal_id
             LEFT JOIN editor e ON e.jurnal_id=ja.jurnal_id
             WHERE " . EMAIL_COND . " AND ja.email_login_sent_at IS NULL"
        )['c'] ?? 0);

        echo json_encode(['ok' => true, 'processed' => count($rows), 'remaining' => $rem, 'log' => $log]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
    exit;
}

/* ── Statistik ─────────────────────────────────────── */
$sent_expr = $has_col ? "SUM(ja.email_login_sent_at IS NOT NULL)" : "0";
$stat = fetch_one(
    "SELECT COUNT(*) AS total, {$sent_expr} AS sent
     FROM jurnal_accounts ja
     JOIN jurnals j ON j.id=ja.jurnal_id
     LEFT JOIN editor e ON e.jurnal_id=ja.jurnal_id
     WHERE " . EMAIL_COND
) ?: ['total' => 0, 'sent' => 0];
$total   = (int)$stat['total'];
$sent    = (int)$stat['sent'];
$unsent  = $total - $sent;
$no_email = (int)(fetch_one("SELECT COUNT(*) c FROM jurnal_accounts")['c'] ?? 0) - $total;
?>
<style>
.se-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.se-card{border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;background:#fff}
.se-card .n{font-size:26px;font-weight:800;color:#1e3a8a;line-height:1}
.se-card .l{font-size:12px;color:#64748b;margin-top:4px}
.se-bar{height:10px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin:6px 0 18px}
.se-bar span{display:block;height:100%;background:linear-gradient(90deg,#16a34a,#22c55e);width:0;transition:width .3s}
.se-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.se-log{border:1px solid #e5e7eb;border-radius:8px;max-height:340px;overflow:auto;font-size:13px}
.se-log div{padding:7px 12px;border-bottom:1px solid #f1f5f9;display:flex;gap:8px;align-items:baseline}
.se-log div:last-child{border-bottom:none}
.se-log .ok{color:#15803d}.se-log .bad{color:#b91c1c}
.se-log .jn{font-weight:600;flex:1;min-width:0}
.se-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:13px;padding:10px 12px;border-radius:8px;margin-bottom:16px}
.se-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:14px;padding:12px 14px;border-radius:8px;margin-bottom:16px}
.se-spin{display:none;width:16px;height:16px;border:2px solid #cbd5e1;border-top-color:#1e3a8a;border-radius:50%;animation:sesp .7s linear infinite;vertical-align:middle}
.se-spin.on{display:inline-block}
@keyframes sesp{to{transform:rotate(360deg)}}
#seStatus{font-weight:600;color:#1e3a8a}
</style>

<div class="page-head">
  <h1>✉️ Sebar Akun Login ke Editor</h1>
  <div class="page-head-actions"><a href="account.php?tab=jurnal" class="btn">&larr; Kembali</a></div>
</div>

<?php if (!$has_col): ?>
<div class="se-err">
  ⛔ <strong>Kolom <code>email_login_sent_at</code> belum ada di database ini.</strong><br>
  Jalankan <code>sql_email_login_sent.sql</code> di phpMyAdmin server ini dulu, lalu muat ulang halaman.
  Tanpa kolom itu, pengiriman tidak bisa berjalan (semua angka tampil 0).
</div>
<?php endif; ?>

<div class="se-warn">
  ⚠️ <strong>Jalankan halaman ini dari server ppj</strong> (ppj.jurnalsinta.id), bukan localhost/XAMPP —
  blast dari IP rumah rawan diblokir provider email. Pengiriman diberi jeda otomatis (throttle) agar aman.
</div>

<div class="se-cards">
  <div class="se-card"><div class="n" id="cTotal"><?= $total ?></div><div class="l">Editor ber-email valid</div></div>
  <div class="se-card"><div class="n" id="cSent" style="color:#16a34a"><?= $sent ?></div><div class="l">Sudah terkirim</div></div>
  <div class="se-card"><div class="n" id="cUnsent" style="color:#ea580c"><?= $unsent ?></div><div class="l">Belum terkirim</div></div>
  <div class="se-card"><div class="n" style="color:#94a3b8"><?= $no_email ?></div><div class="l">Tanpa email valid</div></div>
</div>
<div class="se-bar"><span id="seBar" style="width:<?= $total? round($sent/$total*100):0 ?>%"></span></div>

<div class="se-actions">
  <button class="btn btn-primary" id="btnStart">▶️ Kirim yang belum terkirim</button>
  <button class="btn" id="btnForce" title="Kirim ulang ke SEMUA editor, termasuk yang sudah">🔁 Kirim ulang semua</button>
  <button class="btn btn-danger" id="btnReset" title="Reset penanda terkirim">♻️ Reset status</button>
  <span class="se-spin" id="seSpin"></span>
  <span id="seStatus" class="small" style="align-self:center"></span>
</div>

<div class="se-log" id="seLog"></div>

<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  const HAS_COL = <?= $has_col ? 'true' : 'false' ?>;
  const WAIT_MS = 1500;   // jeda antar-batch (throttle)
  const LIMIT   = 1;      // 1 email per request -> tiap request pendek, aman timeout
  let total=<?= $total ?>, sent=<?= $sent ?>, running=false;
  const $=id=>document.getElementById(id);
  const log=$('seLog');
  const spin=on=>$('seSpin').classList.toggle('on', on);

  function setStat(){ $('cSent').textContent=sent; $('cUnsent').textContent=Math.max(0,total-sent);
    $('seBar').style.width=(total?Math.round(sent/total*100):0)+'%'; }
  function addLog(items){ items.forEach(it=>{ const d=document.createElement('div');
    d.innerHTML='<span class="jn">'+esc(it.jurnal)+'</span><span class="'+(it.ok?'ok':'bad')+'">'
      +(it.ok?'✓ terkirim':'✗ '+esc(it.msg))+'</span>'; log.prepend(d); }); }
  function esc(s){ return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

  function post(body){ const b=new URLSearchParams(body); b.set('_csrf',csrf);
    return fetch('send_email_all.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b})
      .then(async r=>{ const t=await r.text(); try{ return JSON.parse(t); }
        catch(e){ throw new Error('HTTP '+r.status+' — '+(t.replace(/<[^>]+>/g,' ').trim().slice(0,140)||'respons kosong')); } }); }

  let prevRem=-1;
  function loop(){
    if(!running) return;
    spin(true); $('seStatus').textContent='Mengirim…';
    post({act:'send',limit:LIMIT}).then(d=>{
      if(!d.ok){ spin(false); $('seStatus').textContent=d.msg||'Gagal.'; running=false; toggle(false); return; }
      addLog(d.log||[]);
      sent += (d.log||[]).filter(x=>x.ok).length;
      setStat();
      // Berhenti bila tak ada baris lagi, atau batch gagal total (sisa tak berkurang).
      const noProgress = (prevRem!==-1 && d.remaining>=prevRem);
      prevRem = d.remaining;
      if(d.processed>0 && d.remaining>0 && !noProgress){
        $('seStatus').textContent='Sisa '+d.remaining+' — jeda…';
        setTimeout(loop, WAIT_MS);
      } else {
        spin(false);
        $('seStatus').textContent = d.remaining>0
          ? ('Berhenti: '+d.remaining+' gagal terkirim (cek email/SMTP).')
          : ('Selesai. '+sent+'/'+total+' terkirim.');
        running=false; toggle(false);
      }
    }).catch(err=>{ spin(false); $('seStatus').textContent='Gagal: '+(err.message||'koneksi'); running=false; toggle(false); });
  }
  function start(){ prevRem=-1; running=true; toggle(true); loop(); }
  function toggle(on){ $('btnStart').disabled=on; $('btnForce').disabled=on; $('btnReset').disabled=on; }

  function guard(){
    if(!HAS_COL){ alert('Kolom email_login_sent_at belum ada. Jalankan sql_email_login_sent.sql dulu.'); return false; }
    if(Math.max(0,total-sent)===0){ alert('Tidak ada editor yang perlu dikirim (semua sudah terkirim atau tidak ada email valid).'); return false; }
    return true;
  }
  $('btnStart').onclick=()=>{ if(running)return; if(!guard())return; if(!confirm('Kirim email akun ke editor yang belum terkirim?'))return; start(); };
  $('btnForce').onclick=()=>{ if(running)return; if(!HAS_COL){alert('Jalankan sql_email_login_sent.sql dulu.');return;}
    if(total===0){alert('Tidak ada editor dengan email valid.');return;}
    if(!confirm('KIRIM ULANG ke SEMUA editor (reset status lalu kirim semua)?'))return;
    toggle(true); post({act:'reset'}).then(d=>{ if(!d.ok){alert(d.msg||'Gagal');toggle(false);return;} sent=0; setStat(); start(); })
      .catch(err=>{ alert('Gagal: '+(err.message||'koneksi')); toggle(false); }); };
  $('btnReset').onclick=()=>{ if(running)return; if(!HAS_COL){alert('Jalankan sql_email_login_sent.sql dulu.');return;}
    if(!confirm('Reset penanda "sudah terkirim" untuk semua akun?'))return;
    toggle(true); post({act:'reset'}).then(d=>{ sent=0; setStat(); $('seStatus').textContent=d.msg||''; toggle(false); })
      .catch(err=>{ $('seStatus').textContent='Gagal: '+(err.message||'koneksi'); toggle(false); }); };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
