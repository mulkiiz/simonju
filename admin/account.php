<?php
/**
 * admin/account.php — Manajemen akun (tab: Admin | Akun Jurnal + paging).
 */
$page_title = 'Akun';
require_once __DIR__ . '/../includes/header_admin.php';

$msg = $err = '';

/* ── Notifikasi dari redirect ──────────────────────── */
if (!empty($_GET['reset_ok']))  $msg = h($_GET['reset_ok']);
if (!empty($_GET['reset_err'])) $err = h($_GET['reset_err']);
if (!empty($_GET['email_ok']))  $msg = h($_GET['email_ok']);
if (!empty($_GET['email_err'])) $err = h($_GET['email_err']);

/* ── Ganti password admin ──────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = $_POST['old_pass'] ?? '';
    $new = $_POST['new_pass'] ?? '';
    $cnf = $_POST['confirm_pass'] ?? '';
    if ($new !== $cnf) {
        $err = 'Konfirmasi password tidak cocok.';
    } else {
        [$ok, $m] = change_password($_SESSION['uid'], $old, $new);
        $ok ? $msg = $m : $err = $m;
    }
}

/* ── Data akun jurnal + paging ─────────────────────── */
$per_page = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$search   = trim($_GET['q'] ?? '');

$where = '';
$params = [];
$types  = '';
if ($search !== '') {
    $where  = "WHERE ja.username LIKE ? OR j.nama_jurnal LIKE ? OR j.unit_kerja LIKE ?";
    $like   = "%{$search}%";
    $params = [$like, $like, $like];
    $types  = 'sss';
}

$total_row = fetch_one(
    "SELECT COUNT(*) AS cnt FROM jurnal_accounts ja JOIN jurnals j ON j.id=ja.jurnal_id {$where}",
    $types, $params
);
$total   = (int)($total_row['cnt'] ?? 0);
$pages   = max(1, ceil($total / $per_page));
$page    = min($page, $pages);
$offset  = ($page - 1) * $per_page;

$jurnal_accounts = fetch_all(
    "SELECT ja.id, ja.jurnal_id, ja.username, ja.password_hash, ja.failed_attempts, ja.locked_until, ja.created_at,
            j.nama_jurnal, j.konfirmasi_token, j.unit_kerja,
            e.email AS email_editor, e.nama AS nama_editor
     FROM jurnal_accounts ja
     JOIN jurnals j ON j.id = ja.jurnal_id
     LEFT JOIN editor e ON e.jurnal_id = ja.jurnal_id
     {$where}
     ORDER BY j.nama_jurnal ASC
     LIMIT {$per_page} OFFSET {$offset}",
    $types, $params
);

/* ── Admin users ───────────────────────────────────── */
$admins = fetch_all("SELECT id, username, created_at FROM users ORDER BY id ASC");

/* ── Tab aktif ─────────────────────────────────────── */
$tab = ($_GET['tab'] ?? 'admin');
if (!in_array($tab, ['admin', 'jurnal'])) $tab = 'admin';
?>

<style>
/* ── Tabs ─────────────────────────────────────── */
.tabs { display:flex; gap:0; border-bottom:2px solid var(--border, #d0d5dd); margin-bottom:24px; }
.tabs a {
    padding:10px 24px; text-decoration:none; font-weight:600; font-size:14px;
    color:var(--text-muted, #667085); border-bottom:2px solid transparent;
    margin-bottom:-2px; transition:all .2s;
}
.tabs a:hover { color:var(--primary, #2563eb); }
.tabs a.active {
    color:var(--primary, #2563eb); border-bottom-color:var(--primary, #2563eb);
}
.tab-badge {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:22px; height:22px; padding:0 6px; border-radius:99px;
    font-size:12px; font-weight:700; margin-left:6px;
    background:var(--bg-subtle, #f2f4f7); color:var(--text-muted, #667085);
}
.tabs a.active .tab-badge {
    background:var(--primary, #2563eb); color:#fff;
}

/* ── Search bar ──────────────────────────────── */
.search-bar { display:flex; gap:8px; margin-bottom:16px; max-width:420px; }
.search-bar input {
    flex:1; padding:8px 12px; border:1px solid var(--border, #d0d5dd);
    border-radius:6px; font-size:14px;
}
.search-bar button {
    padding:8px 16px; border:none; border-radius:6px; cursor:pointer;
    background:var(--primary, #2563eb); color:#fff; font-size:14px; font-weight:600;
}

/* ── Table ───────────────────────────────────── */
.tbl-wrap { overflow-x:auto; }
.tbl { width:100%; border-collapse:collapse; font-size:13px; }
.tbl th { background:var(--bg-subtle, #f9fafb); font-weight:600; text-align:left; white-space:nowrap; }
.tbl th, .tbl td { padding:10px 14px; border-bottom:1px solid var(--border, #eaecf0); }
.tbl tr:hover td { background:var(--bg-subtle, #f9fafb); }
.tbl .mono { font-family:'Courier New',monospace; font-size:12px; letter-spacing:.3px; }
.tbl .muted { color:var(--text-muted, #667085); font-size:12px; }

/* ── Badge ───────────────────────────────────── */
.badge { display:inline-block; padding:2px 10px; border-radius:99px; font-size:11px; font-weight:600; }
.badge-ok  { background:#ecfdf3; color:#027a48; }
.badge-warn { background:#fffaeb; color:#b54708; }
.badge-lock { background:#fef3f2; color:#b42318; }

/* ── Pagination ──────────────────────────────── */
.paging { display:flex; align-items:center; gap:4px; margin-top:16px; flex-wrap:wrap; }
.paging a, .paging span {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:34px; height:34px; padding:0 8px; border-radius:6px;
    font-size:13px; font-weight:500; text-decoration:none;
    border:1px solid var(--border, #d0d5dd); color:var(--text, #344054);
}
.paging a:hover { background:var(--bg-subtle, #f2f4f7); }
.paging .cur { background:var(--primary, #2563eb); color:#fff; border-color:var(--primary, #2563eb); }
.paging .dot { border:none; color:var(--text-muted, #667085); }
.paging .info { border:none; font-size:12px; color:var(--text-muted, #667085); margin-left:auto; }

/* ── Action buttons in table ────────────────── */
.tbl-actions { display:flex; gap:4px; }
.btn-icon {
    display:inline-flex; align-items:center; justify-content:center;
    width:30px; height:30px; border-radius:6px; border:1px solid var(--border, #d0d5dd);
    background:var(--bg, #fff); cursor:pointer; font-size:14px; text-decoration:none;
    transition:background .15s;
}
.btn-icon:hover { background:var(--bg-subtle, #f2f4f7); }
.btn-icon-danger:hover { background:#fef3f2; border-color:#fda29b; }
.btn-icon-primary:hover { background:#eff8ff; border-color:#84caff; }
.email-cell { font-size:12px; color:var(--text-muted,#667085); max-width:180px; word-break:break-all; }
.email-empty { color:var(--text-muted,#667085); font-size:12px; font-style:italic; }

/* ── Toolbar row ─────────────────────────────── */
.toolbar-row { display:flex; gap:8px; margin-bottom:16px; align-items:flex-end; flex-wrap:wrap; }
.toolbar-row .search-bar { margin-bottom:0; }

/* ── Admin card ──────────────────────────────── */
.admin-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; margin-bottom:24px; }
.admin-card {
    border:1px solid var(--border, #eaecf0); border-radius:10px; padding:20px;
    background:var(--bg, #fff);
}
.admin-card h3 { margin:0 0 4px; font-size:15px; }
.admin-card .meta { font-size:12px; color:var(--text-muted, #667085); }
</style>

<div class="page-head">
  <h1>⚙️ Manajemen Akun</h1>
</div>

<?php if ($msg): ?><div class="alert alert-info"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- ── Tabs ────────────────────────────────────── -->
<div class="tabs">
  <a href="?tab=admin" class="<?= $tab==='admin'?'active':'' ?>">
    🛡️ Akun Admin <span class="tab-badge"><?= count($admins) ?></span>
  </a>
  <a href="?tab=jurnal" class="<?= $tab==='jurnal'?'active':'' ?>">
    📋 Akun Jurnal <span class="tab-badge"><?= $total ?></span>
  </a>
</div>

<?php if ($tab === 'admin'): ?>
<!-- ══════════════ TAB: ADMIN ══════════════ -->
<div class="admin-cards">
<?php foreach ($admins as $a): ?>
  <div class="admin-card">
    <h3>👤 <?= h($a['username']) ?></h3>
    <p class="meta">ID: <?= $a['id'] ?> · Dibuat: <?= $a['created_at'] ? date('d M Y', strtotime($a['created_at'])) : '-' ?></p>
  </div>
<?php endforeach; ?>
</div>

<form method="post" class="form-grid" style="max-width:480px">
  <?= csrf_field() ?>
  <fieldset>
    <legend>🔒 Ganti Password — <?= h($_SESSION['uname']) ?></legend>
    <label>Password lama
      <input type="password" name="old_pass" required>
    </label>
    <label>Password baru (min. 8 karakter)
      <input type="password" name="new_pass" minlength="8" required>
    </label>
    <label>Konfirmasi password baru
      <input type="password" name="confirm_pass" minlength="8" required>
    </label>
  </fieldset>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary">💾 Simpan Password</button>
  </div>
</form>

<?php else: ?>
<!-- ══════════════ TAB: AKUN JURNAL ══════════════ -->
<div class="toolbar-row">
  <form class="search-bar" method="get" style="margin-bottom:0">
    <input type="hidden" name="tab" value="jurnal">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Cari username, nama jurnal, unit kerja…">
    <button type="submit">🔍 Cari</button>
    <?php if ($search !== ''): ?>
      <a href="?tab=jurnal" class="btn btn-secondary" style="padding:8px 12px;font-size:14px">✕</a>
    <?php endif; ?>
  </form>
  <form method="post" action="send_email_jurnal.php">
    <?= csrf_field() ?>
    <input type="hidden" name="test_email" value="1">
    <button type="submit" class="btn btn-secondary" title="Kirim draft email ke admiportfolio@gmail.com untuk keperluan testing">
      🧪 Test Email
    </button>
  </form>
</div>

<?php if ($search !== ''): ?>
  <p class="muted small" style="margin-bottom:12px">
    Hasil pencarian "<strong><?= h($search) ?></strong>" — <?= $total ?> akun ditemukan
  </p>
<?php endif; ?>

<?php if (empty($jurnal_accounts)): ?>
  <div class="empty"><p>Tidak ada data akun jurnal.</p></div>
<?php else: ?>
<div class="tbl-wrap">
<table class="tbl">
  <thead>
    <tr>
      <th>#</th>
      <th>Username</th>
      <th>Nama Jurnal</th>
      <th>Unit Kerja</th>
      <th>Email Ketua Editor</th>
      <th>Password (Token)</th>
      <th>Status</th>
      <th>Dibuat</th>
      <th>Aksi</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($jurnal_accounts as $i => $a):
      $no = $offset + $i + 1;
      $locked = !empty($a['locked_until']) && strtotime($a['locked_until']) > time();
      $attempts = (int)($a['failed_attempts'] ?? 0);
  ?>
    <tr>
      <td class="muted"><?= $no ?></td>
      <td><strong class="mono"><?= h($a['username']) ?></strong></td>
      <td><?= h($a['nama_jurnal']) ?></td>
      <td class="muted"><?= h($a['unit_kerja'] ?? '-') ?></td>
      <td>
        <?php
          $em = trim($a['email_editor'] ?? '');
          if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)):
        ?>
          <span class="email-cell"><?= h($em) ?></span>
        <?php else: ?>
          <span class="email-empty">—</span>
        <?php endif; ?>
      </td>
      <td class="mono"><?= h($a['konfirmasi_token'] ?? '-') ?></td>
      <td>
        <?php if ($locked): ?>
          <span class="badge badge-lock">🔒 Terkunci</span>
        <?php elseif ($attempts >= 3): ?>
          <span class="badge badge-warn">⚠️ <?= $attempts ?>× gagal</span>
        <?php else: ?>
          <span class="badge badge-ok">✅ Aktif</span>
        <?php endif; ?>
      </td>
      <td class="muted"><?= $a['created_at'] ? date('d M Y', strtotime($a['created_at'])) : '-' ?></td>
      <td>
        <div class="tbl-actions">
          <!-- Reset ke token -->
          <form method="post" action="reset_jurnal_pass.php" style="display:inline"
                onsubmit="return confirm('Reset password <?= h(addslashes($a['username'])) ?> ke token asal?')">
            <?= csrf_field() ?>
            <input type="hidden" name="ja_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="btn-icon btn-icon-danger" title="Reset password ke token asal">🔑</button>
          </form>
          <!-- Kirim email (hanya jika email ada) -->
          <?php if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)): ?>
          <form method="post" action="send_email_jurnal.php" style="display:inline"
                onsubmit="return confirm('Kirim email login ke <?= h(addslashes($em)) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="ja_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="btn-icon btn-icon-primary" title="Kirim email login ke ketua editor">✉️</button>
          </form>
          <?php else: ?>
            <span class="btn-icon" style="opacity:.35;cursor:default" title="Email belum diisi">✉️</span>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div class="paging">
  <?php
  $qs = http_build_query(array_filter(['tab'=>'jurnal','q'=>$search]));
  $link = function($p) use ($qs) {
      return '?' . $qs . ($qs ? '&' : '') . "page={$p}";
  };

  if ($page > 1): ?>
    <a href="<?= $link($page-1) ?>">‹</a>
  <?php endif;

  // Page numbers with ellipsis
  $range = 2;
  for ($p = 1; $p <= $pages; $p++):
      if ($p == 1 || $p == $pages || abs($p - $page) <= $range):
          if ($p == $page): ?>
            <span class="cur"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= $link($p) ?>"><?= $p ?></a>
          <?php endif;
          $last_shown = $p;
      elseif (isset($last_shown) && $p == $last_shown + 1): ?>
        <span class="dot">…</span>
      <?php
          $last_shown = $p;
      endif;
  endfor;

  if ($page < $pages): ?>
    <a href="<?= $link($page+1) ?>">›</a>
  <?php endif; ?>
  <span class="info"><?= $total ?> akun · hal <?= $page ?>/<?= $pages ?></span>
</div>
<?php endif; ?>

<?php endif; // empty check ?>
<?php endif; // tab ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
