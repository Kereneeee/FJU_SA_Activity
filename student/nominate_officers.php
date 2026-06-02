<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$current_page = 'nominate_officers';
$user_id      = $_SESSION['user_id'] ?? null;
$club_id      = $_SESSION['active_club_id'] ?? '';
$club_name    = $_SESSION['active_club_name'] ?? '';

if (!$user_id || !$club_id) {
    header('Location: dashboard.php');
    exit();
}

// 確認目前身分是社長
$chk = $conn->prepare(
    "SELECT membership_id FROM club_members WHERE user_id=? AND club_id=? AND is_officer=1 AND officer_title='社長' LIMIT 1"
);
$chk->bind_param("is", $user_id, $club_id);
$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}
$chk->close();

$message      = '';
$message_type = '';
$submit_errors = [];
$submit_count  = 0;

// ── 批次送出提名 ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch_nominate') {
    $nominations = $_POST['nominations'] ?? [];

    if (empty($nominations)) {
        $message      = '請先加入至少一筆提名。';
        $message_type = 'danger';
    } else {
        foreach ($nominations as $nom) {
            $nominated_id  = intval($nom['user_id'] ?? 0);
            $officer_title = trim($nom['officer_title'] ?? '');

            if (!$nominated_id) {
                $submit_errors[] = '有一筆提名資料不完整，已略過。';
                continue;
            }

            // 確認沒有重複 pending
            $dup = $conn->prepare(
                "SELECT nomination_id FROM officer_nominations WHERE club_id=? AND nominated_user_id=? AND status='pending' LIMIT 1"
            );
            $dup->bind_param("si", $club_id, $nominated_id);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $submit_errors[] = "此成員已有待審核的提名，已略過。";
                $dup->close();
                continue;
            }
            $dup->close();

            $ins = $conn->prepare(
                "INSERT INTO officer_nominations (club_id, nominated_user_id, nominator_user_id, officer_title) VALUES (?,?,?,?)"
            );
            $ins->bind_param("siis", $club_id, $nominated_id, $user_id, $officer_title);
            if ($ins->execute()) {
                $submit_count++;
            } else {
                $submit_errors[] = '新增失敗：' . $ins->error;
            }
            $ins->close();
        }

        if ($submit_count > 0) {
            $message      = "成功送出 {$submit_count} 筆提名申請，請等待管理者審核。";
            $message_type = 'success';
        }
        if (!empty($submit_errors)) {
            $message     .= ($message ? '<br>' : '') . implode('<br>', $submit_errors);
            $message_type = $submit_count > 0 ? 'warning' : 'danger';
        }
    }
}

// 撤回提名
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $nomination_id = intval($_POST['nomination_id'] ?? 0);
    $del = $conn->prepare(
        "DELETE FROM officer_nominations WHERE nomination_id=? AND nominator_user_id=? AND status='pending'"
    );
    $del->bind_param("ii", $nomination_id, $user_id);
    $message      = $del->execute() ? '已撤回提名。' : '撤回失敗。';
    $message_type = 'success';
    $del->close();
}

// ── 取得提名紀錄 ──────────────────────────────────────────────
$pend = $conn->prepare(
    "SELECT n.nomination_id, n.officer_title, n.created_at, n.status, n.review_note,
            u.name AS nominated_name, u.student_id AS nominated_sid
     FROM officer_nominations n
     JOIN users u ON n.nominated_user_id = u.user_id
     WHERE n.club_id = ? AND n.nominator_user_id = ?
     ORDER BY n.created_at DESC"
);
$pend->bind_param("si", $club_id, $user_id);
$pend->execute();
$my_nominations = $pend->get_result()->fetch_all(MYSQLI_ASSOC);
$pend->close();

$student_name = $_SESSION['student_name'] ?? '社長';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>幹部提名 - 輔仁大學課外活動指導組</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary: #1e4d6b; --sidebar: #14394f; --bg: #f7f5ef; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--bg); color: #1f2937; }
        .sidebar {
            position: fixed; top: 0; left: 0; width: 260px; height: 100vh;
            background: linear-gradient(180deg, var(--primary), var(--sidebar));
            color: white; padding: 1.5rem 0.8rem; overflow-y: auto;
            box-shadow: 3px 0 15px rgba(0,0,0,.12); z-index: 1200;
        }
        .sidebar .brand { text-align: center; margin-bottom: 1.5rem; }
        .sidebar .brand h4 { margin: 0; font-size: 1.1rem; line-height: 1.4; font-weight: 700; }
        .sidebar .nav-link {
            display: flex; align-items: center; gap: .75rem;
            color: rgba(255,255,255,.9); padding: .85rem 1rem; margin: .2rem 0;
            border-radius: 16px; transition: background .25s, transform .15s; text-decoration: none;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active { background: rgba(255,255,255,.15); color: #fff; transform: translateX(4px); }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem .5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,.12); }

        .main-content { margin-left: 260px; min-height: 100vh; }
        .top-navbar {
            background: white; border-bottom: 1px solid #e9ecef;
            padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1100;
        }
        .content-wrapper { padding: 1.5rem 2rem 3rem; max-width: 820px; }

        .panel { background: white; border-radius: 18px; box-shadow: 0 6px 24px rgba(15,23,42,.06); margin-bottom: 1.5rem; overflow: hidden; }
        .panel-header { padding: 1.1rem 1.5rem; border-bottom: 1px solid #e9ecef; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: .5rem; font-size: 1rem; }
        .panel-body { padding: 1.25rem 1.5rem; }

        /* 查詢區 */
        .search-block { display: flex; gap: .75rem; align-items: flex-end; flex-wrap: wrap; }
        .search-result {
            border: 1.5px solid #e5e7eb; border-radius: 12px; padding: .9rem 1.1rem;
            background: #f8fafc; margin-top: 1rem; display: none;
            align-items: center; gap: 1rem; flex-wrap: wrap;
        }
        .search-result.show { display: flex; }
        .search-result.error { border-color: #fca5a5; background: #fef2f2; }
        .avatar-sm {
            width: 40px; height: 40px; border-radius: 50%; background: var(--primary);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1rem; flex-shrink: 0;
        }
        .not-member-warn { color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: .4rem .8rem; font-size: .82rem; }

        /* 清單 */
        .nom-list { min-height: 60px; }
        .nom-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: .7rem .9rem; border: 1px solid #e5e7eb; border-radius: 10px;
            background: white; margin-bottom: .5rem; gap: .75rem;
        }
        .nom-item .info { flex: 1; min-width: 0; }
        .nom-item .name { font-weight: 600; }
        .nom-item .meta { font-size: .82rem; color: #6b7280; }
        .title-tag { display: inline-block; background: #dbeafe; color: #1e40af; border-radius: 999px; padding: .15rem .6rem; font-size: .78rem; font-weight: 600; margin-left: .4rem; }
        .empty-hint { color: #9ca3af; font-size: .88rem; text-align: center; padding: 1.2rem; }

        /* 紀錄 */
        .rec-row { display: flex; align-items: center; justify-content: space-between; padding: .75rem 0; border-bottom: 1px solid #f0f0f0; gap: .75rem; }
        .rec-row:last-child { border-bottom: none; }
        .badge-pending  { background: #fff3cd; color: #856404; padding: .2rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
        .badge-approved { background: #d1e7dd; color: #0f5132; padding: .2rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
        .badge-rejected { background: #f8d7da; color: #842029; padding: .2rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
    </style>
</head>
<body>
<?php include(__DIR__ . "/../includes/sidebar.php"); ?>

<main class="main-content">
    <header class="top-navbar">
        <div>
            <ol class="breadcrumb mb-0" style="font-size:.85rem;">
                <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                <li class="breadcrumb-item active">幹部提名</li>
            </ol>
            <h4 class="mt-1 mb-0">幹部提名 — <?= htmlspecialchars($club_name) ?></h4>
        </div>
    </header>

    <section class="content-wrapper">

        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- ① 查詢學號 + 加入清單 -->
        <div class="panel">
            <div class="panel-header"><i class="bi bi-search"></i> 查詢學生並加入提名清單</div>
            <div class="panel-body">

                <!-- 學號查詢 -->
                <div class="search-block">
                    <div>
                        <label class="form-label fw-semibold mb-1">輸入學號</label>
                        <input type="text" id="sidInput" class="form-control" style="width:200px;"
                               placeholder="例：410123456" maxlength="20">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="lookupStudent()">
                        <i class="bi bi-search"></i> 查詢
                    </button>
                </div>

                <!-- 查詢結果 -->
                <div id="searchResult" class="search-result">
                    <div class="avatar-sm" id="resAvatar"></div>
                    <div style="flex:1;">
                        <div id="resName" style="font-weight:700;font-size:1rem;"></div>
                        <div id="resSid" style="font-size:.83rem;color:#6b7280;"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-end flex-wrap">
                        <div>
                            <label class="form-label fw-semibold mb-1" style="font-size:.85rem;">身分</label>
                            <select id="roleSelect" class="form-select form-select-sm" style="width:120px;" onchange="handleRoleChange()">
                                <option value="一般成員">一般成員</option>
                                <option value="幹部">幹部</option>
                            </select>
                        </div>
                        <div id="titleWrap">
                            <label class="form-label fw-semibold mb-1" style="font-size:.85rem;">幹部職稱 <span class="text-danger">*</span></label>
                            <input type="text" id="titleInput" class="form-control form-control-sm"
                                   style="width:180px;" placeholder="例：器材幹部" maxlength="50">
                        </div>
                        <button type="button" id="addBtn" class="btn btn-success btn-sm" onclick="addToList()">
                            <i class="bi bi-plus-circle"></i> 加入清單
                        </button>
                    </div>
                </div>

                <div id="errMsg" class="alert alert-danger mt-3" style="display:none;"></div>
            </div>
        </div>

        <!-- ② 待提名清單 -->
        <div class="panel">
            <div class="panel-header">
                <i class="bi bi-list-check"></i> 待提名清單
                <span id="listCount" style="background:#e0f2fe;color:#0369a1;border-radius:999px;padding:.1rem .55rem;font-size:.78rem;margin-left:.4rem;">0</span>
            </div>
            <div class="panel-body">
                <div id="nomList" class="nom-list">
                    <div class="empty-hint" id="emptyHint"><i class="bi bi-inbox"></i> 尚未加入任何提名，請先查詢學號。</div>
                </div>

                <!-- 送出表單（由 JS 動態填入） -->
                <form method="POST" id="submitForm" style="margin-top:1.25rem;">
                    <input type="hidden" name="action" value="batch_nominate">
                    <div id="hiddenFields"></div>
                    <button type="submit" id="submitBtn" class="btn btn-primary" disabled onclick="return validateSubmit()">
                        <i class="bi bi-send"></i> 送出所有提名申請
                    </button>
                    <span class="text-muted ms-2" style="font-size:.85rem;">提交後由課外活動指導組審核</span>
                </form>
            </div>
        </div>

        <!-- ③ 我的提名紀錄 -->
        <div class="panel">
            <div class="panel-header"><i class="bi bi-clock-history"></i> 我的提名紀錄</div>
            <div class="panel-body">
                <?php if (empty($my_nominations)): ?>
                    <p class="text-muted mb-0">目前尚無提名紀錄。</p>
                <?php else: ?>
                    <?php foreach ($my_nominations as $n): ?>
                    <div class="rec-row">
                        <div style="flex:1;min-width:0;">
                            <span style="font-weight:600;"><?= htmlspecialchars($n['nominated_name']) ?></span>
                            <span style="color:#9ca3af;font-size:.82rem;margin-left:.4rem;">學號 <?= htmlspecialchars($n['nominated_sid']) ?></span>
                            <span class="title-tag"><?= htmlspecialchars($n['officer_title']) ?></span>
                            <br>
                            <span style="color:#9ca3af;font-size:.8rem;"><?= date('Y/m/d H:i', strtotime($n['created_at'])) ?></span>
                            <?php if ($n['status'] === 'rejected' && !empty($n['review_note'])): ?>
                            <br><span style="color:#842029;font-size:.8rem;">駁回原因：<?= htmlspecialchars($n['review_note']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <?php if ($n['status'] === 'pending'): ?>
                                <span class="badge-pending"><i class="bi bi-hourglass-split"></i> 待審核</span>
                                <form method="POST" onsubmit="return confirm('確定撤回此提名？')">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="nomination_id" value="<?= $n['nomination_id'] ?>">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">撤回</button>
                                </form>
                            <?php elseif ($n['status'] === 'approved'): ?>
                                <span class="badge-approved"><i class="bi bi-check-circle"></i> 已核准</span>
                            <?php else: ?>
                                <span class="badge-rejected"><i class="bi bi-x-circle"></i> 已駁回</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CLUB_ID = <?= json_encode($club_id) ?>;

// 目前找到的學生
let currentStudent = null;
// 清單陣列 [{user_id, name, student_id, officer_title}]
let nominationList = [];

// ── 身分切換：幹部才顯示職稱欄 ───────────────────────────────
function handleRoleChange() {
    const role      = document.getElementById('roleSelect').value;
    const titleWrap = document.getElementById('titleWrap');
    const titleInput = document.getElementById('titleInput');
    if (role === '幹部') {
        titleWrap.style.display = '';
    } else {
        titleWrap.style.display = 'none';
        titleInput.value = '';
    }
}

// ── 查詢學號 ──────────────────────────────────────────────────
function lookupStudent() {
    const sid    = document.getElementById('sidInput').value.trim();
    const errBox = document.getElementById('errMsg');
    const result = document.getElementById('searchResult');

    errBox.style.display = 'none';
    result.classList.remove('show', 'error');
    currentStudent = null;
    document.getElementById('titleInput').value = '';
    document.getElementById('roleSelect').value = '一般成員';
    handleRoleChange();

    if (!sid) { showErr('請輸入學號。'); return; }

    fetch(`../api/lookup_student.php?student_id=${encodeURIComponent(sid)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { showErr(data.message || '查詢失敗'); return; }
            currentStudent = data.user;
            document.getElementById('resAvatar').textContent = data.user.name.charAt(0);
            document.getElementById('resName').textContent   = data.user.name;
            document.getElementById('resSid').textContent    = '學號：' + data.user.student_id;
            result.classList.add('show');
        })
        .catch(() => showErr('網路錯誤，請稍後再試。'));
}

function showErr(msg) {
    const e = document.getElementById('errMsg');
    e.textContent = msg;
    e.style.display = 'block';
}

// ── 加入清單 ──────────────────────────────────────────────────
function addToList() {
    if (!currentStudent) return;

    const role  = document.getElementById('roleSelect').value;
    const title = document.getElementById('titleInput').value.trim();

    if (role === '幹部' && !title) {
        alert('請填寫幹部職稱。');
        document.getElementById('titleInput').focus();
        return;
    }

    if (nominationList.some(n => n.user_id === currentStudent.user_id)) {
        alert('此學生已在提名清單中。');
        return;
    }

    nominationList.push({
        user_id:       currentStudent.user_id,
        name:          currentStudent.name,
        student_id:    currentStudent.student_id,
        role:          role,
        officer_title: role === '幹部' ? title : '',
    });

    renderList();
    document.getElementById('sidInput').value = '';
    document.getElementById('titleInput').value = '';
    document.getElementById('roleSelect').value = '一般成員';
    handleRoleChange();
    document.getElementById('searchResult').classList.remove('show');
    document.getElementById('errMsg').style.display = 'none';
    currentStudent = null;
}

// ── 渲染清單 ──────────────────────────────────────────────────
function renderList() {
    const container = document.getElementById('nomList');
    const hint      = document.getElementById('emptyHint');
    const hidden    = document.getElementById('hiddenFields');
    const countBadge = document.getElementById('listCount');
    const submitBtn = document.getElementById('submitBtn');

    countBadge.textContent = nominationList.length;
    submitBtn.disabled = nominationList.length === 0;

    if (nominationList.length === 0) {
        container.innerHTML = '<div class="empty-hint" id="emptyHint"><i class="bi bi-inbox"></i> 尚未加入任何提名，請先查詢學號。</div>';
        hidden.innerHTML = '';
        return;
    }

    let listHtml = '';
    let hiddenHtml = '';

    nominationList.forEach((n, i) => {
        const roleTag = n.role === '幹部'
            ? `<span class="title-tag" style="background:#d1fae5;color:#065f46;">${escHtml(n.officer_title)}</span>`
            : `<span class="title-tag" style="background:#f3f4f6;color:#374151;">一般成員</span>`;

        listHtml += `
        <div class="nom-item" id="nom-item-${i}">
            <div class="info">
                <div class="name">${escHtml(n.name)}<span class="ms-2" style="color:#9ca3af;font-size:.82rem;">學號 ${escHtml(n.student_id)}</span></div>
                <div class="meta">身分：${roleTag}</div>
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFromList(${i})">
                <i class="bi bi-trash"></i>
            </button>
        </div>`;

        hiddenHtml += `
        <input type="hidden" name="nominations[${i}][user_id]" value="${n.user_id}">
        <input type="hidden" name="nominations[${i}][officer_title]" value="${escAttr(n.officer_title)}">`;
    });

    container.innerHTML = listHtml;
    hidden.innerHTML = hiddenHtml;
}

function removeFromList(index) {
    nominationList.splice(index, 1);
    renderList();
}

function validateSubmit() {
    if (nominationList.length === 0) {
        alert('請先加入至少一筆提名。');
        return false;
    }
    return confirm(`確定送出 ${nominationList.length} 筆提名申請？`);
}

// ── 工具 ────────────────────────────────────────────────────
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(str) {
    return String(str).replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Enter 觸發查詢
document.getElementById('sidInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); lookupStudent(); }
});
</script>
</body>
</html>
