<?php
session_start();

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
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

            // 寄信通知所有管理員
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $admins = $conn->query("SELECT email, name FROM users WHERE role='admin' AND email IS NOT NULL");
                if ($admins) {
                    while ($adm = $admins->fetch_assoc()) {
                        sendNominationSubmittedMail($adm['email'], $adm['name'], [
                            'club_name'      => $club_name,
                            'nominator_name' => $student_name,
                            'count'          => $submit_count,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                error_log('[nominate_officers mail] ' . $e->getMessage());
            }
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
            background: var(--primary);
            color: white; padding: 1.5rem 0.8rem; overflow-y: hidden;
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
        .sidebar .nav-link.active { background: #ece8dd; color: #1e4d6b; transform: translateX(4px); }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem .5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,.12); }

        .main-content { margin-left: 260px; min-height: 100vh; }
        .top-navbar {
            background: #d5e3ea; border-bottom: 1px solid #bdd0d9;
            padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1100;
        }
        .top-navbar .breadcrumb { font-size: .8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: .75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
        .content-wrapper { padding: 1.5rem 2rem 3rem; }

        .panel { background: white; border-radius: 18px; box-shadow: 0 6px 24px rgba(15,23,42,.06); margin-bottom: 1.5rem; }
        .panel-header { border-radius: 18px 18px 0 0; }
        .panel-header { padding: 1.1rem 1.5rem; border-bottom: 1px solid #e9ecef; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: .5rem; font-size: 1rem; }
        .panel-body { padding: 1.25rem 1.5rem; }

        /* 查詢區 */
        .search-block { display: flex; gap: .75rem; align-items: flex-end; flex-wrap: wrap; }
        .sid-wrap { position: relative; }
        .autocomplete-dropdown {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 999;
            background: white; border: 1.5px solid #d1d5db; border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,.1); margin-top: 2px; overflow: hidden; display: none;
        }
        .autocomplete-dropdown.show { display: block; }
        .ac-item {
            padding: .6rem .9rem; cursor: pointer; display: flex; align-items: center; gap: .75rem;
            border-bottom: 1px solid #f3f4f6; transition: background .15s;
        }
        .ac-item:last-child { border-bottom: none; }
        .ac-item:hover { background: #f0f9ff; }
        .ac-avatar {
            width: 32px; height: 32px; border-radius: 50%; background: var(--primary);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .85rem; flex-shrink: 0;
        }
        .ac-name { font-weight: 600; font-size: .9rem; }
        .ac-sid  { font-size: .8rem; color: #6b7280; }
        .ac-no-result { padding: .75rem 1rem; color: #9ca3af; font-size: .88rem; text-align: center; }
        .search-result {
            border: 1.5px solid #e5e7eb; border-radius: 12px; padding: .9rem 1.1rem;
            background: #f8fafc; margin-top: 1rem; display: none;
            align-items: center; gap: 1rem; flex-wrap: wrap;
        }
        .search-result.show { display: flex; }
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
    <?php
    $nav_breadcrumbs = [['label'=>'首頁','url'=>'dashboard.php'],['label'=>'幹部提名']];
    $nav_title = '幹部提名 — ' . htmlspecialchars($club_name);
    include __DIR__ . '/../includes/student_navbar.php';
    ?>

    <section class="content-wrapper">

        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4 align-items-start">

            <!-- 左欄：查詢 + 清單 -->
            <div class="col-lg-7">

                <!-- ① 查詢學號 + 加入清單 -->
                <div class="panel">
                    <div class="panel-header"><i class="bi bi-search"></i> 查詢學生並加入提名清單</div>
                    <div class="panel-body">

                        <div class="search-block">
                            <div class="sid-wrap">
                                <label class="form-label fw-semibold mb-1">輸入學號</label>
                                <input type="text" id="sidInput" class="form-control" style="width:220px;"
                                       placeholder="輸入學號搜尋" maxlength="20" autocomplete="off">
                                <div id="acDropdown" class="autocomplete-dropdown"></div>
                            </div>
                        </div>

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

            </div><!-- /左欄 -->

            <!-- 右欄：提名紀錄 -->
            <div class="col-lg-5">
                <div class="panel" style="position:sticky; top:80px;">
                    <div class="panel-header"><i class="bi bi-clock-history"></i> 我的提名紀錄</div>
                    <div class="panel-body" style="max-height:calc(100vh - 180px); overflow-y:auto;">
                        <?php if (empty($my_nominations)): ?>
                            <p class="text-muted mb-0">目前尚無提名紀錄。</p>
                        <?php else: ?>
                            <?php foreach ($my_nominations as $n): ?>
                            <div class="rec-row">
                                <div style="flex:1;min-width:0;">
                                    <span style="font-weight:600;"><?= htmlspecialchars($n['nominated_name']) ?></span>
                                    <span style="color:#9ca3af;font-size:.82rem;margin-left:.4rem;">學號 <?= htmlspecialchars($n['nominated_sid']) ?></span>
                                    <span class="title-tag"><?= htmlspecialchars($n['officer_title'] ?: '一般成員') ?></span>
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
            </div><!-- /右欄 -->

        </div><!-- /row -->

    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CLUB_ID = <?= json_encode($club_id) ?>;

let currentStudent = null;
let nominationList = [];
let acTimer = null;

// ── 工具 ──────────────────────────────────────────────────────
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

// ── 身分切換 ──────────────────────────────────────────────────
function handleRoleChange() {
    const role = document.getElementById('roleSelect').value;
    const wrap = document.getElementById('titleWrap');
    if (role === '幹部') { wrap.style.display = ''; }
    else { wrap.style.display = 'none'; document.getElementById('titleInput').value = ''; }
}

// ── Autocomplete ──────────────────────────────────────────────
const sidInput   = document.getElementById('sidInput');
const acDropdown = document.getElementById('acDropdown');

sidInput.addEventListener('input', function () {
    const q = this.value.trim();
    clearTimeout(acTimer);
    closeDropdown();
    document.getElementById('searchResult').classList.remove('show');
    currentStudent = null;
    if (q.length === 0) return;
    acTimer = setTimeout(() => fetchCandidates(q), 250);
});

sidInput.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDropdown();
});

document.addEventListener('click', function (e) {
    if (!sidInput.contains(e.target) && !acDropdown.contains(e.target)) closeDropdown();
});

function fetchCandidates(q) {
    fetch(`../api/lookup_student.php?student_id=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.users || data.users.length === 0) {
                showDropdown([]);
            } else {
                showDropdown(data.users);
            }
        })
        .catch(() => showDropdown([]));
}

function showDropdown(users) {
    if (users.length === 0) {
        acDropdown.innerHTML = '<div class="ac-no-result"><i class="bi bi-search me-1"></i>查無符合結果</div>';
    } else {
        acDropdown.innerHTML = users.map(u => `
            <div class="ac-item" onclick="selectStudent(${u.user_id}, ${escAttr(JSON.stringify(u.name))}, '${u.student_id}')">
                <div class="ac-avatar">${escHtml(u.name.charAt(0))}</div>
                <div>
                    <div class="ac-name">${escHtml(u.name)}</div>
                    <div class="ac-sid">學號：${escHtml(String(u.student_id))}</div>
                </div>
            </div>`).join('');
    }
    acDropdown.classList.add('show');
}

function closeDropdown() {
    acDropdown.classList.remove('show');
    acDropdown.innerHTML = '';
}

function selectStudent(user_id, name, student_id) {
    currentStudent = { user_id, name, student_id };
    sidInput.value = student_id;
    closeDropdown();
    document.getElementById('resAvatar').textContent = name.charAt(0);
    document.getElementById('resName').textContent   = name;
    document.getElementById('resSid').textContent    = '學號：' + student_id;
    document.getElementById('roleSelect').value = '一般成員';
    document.getElementById('titleInput').value = '';
    handleRoleChange();
    document.getElementById('searchResult').classList.add('show');
    document.getElementById('errMsg').style.display = 'none';
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
    sidInput.value = '';
    document.getElementById('searchResult').classList.remove('show');
    currentStudent = null;
}

// ── 渲染清單（含內嵌編輯） ───────────────────────────────────
function renderList() {
    const container  = document.getElementById('nomList');
    const hidden     = document.getElementById('hiddenFields');
    const countBadge = document.getElementById('listCount');
    const submitBtn  = document.getElementById('submitBtn');

    countBadge.textContent = nominationList.length;
    submitBtn.disabled = nominationList.length === 0;

    if (nominationList.length === 0) {
        container.innerHTML = '<div class="empty-hint"><i class="bi bi-inbox"></i> 尚未加入任何提名，請先查詢學號。</div>';
        hidden.innerHTML = '';
        return;
    }

    let listHtml = '', hiddenHtml = '';
    nominationList.forEach((n, i) => {
        listHtml += `
        <div class="nom-item" id="nom-item-${i}">
            <div class="info">
                <div class="name">${escHtml(n.name)}
                    <span class="ms-2" style="color:#9ca3af;font-size:.82rem;">學號 ${escHtml(String(n.student_id))}</span>
                </div>
                <div class="meta d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <select class="form-select form-select-sm" style="width:110px;" onchange="updateRole(${i}, this.value)">
                        <option value="一般成員" ${n.role === '一般成員' ? 'selected' : ''}>一般成員</option>
                        <option value="幹部"     ${n.role === '幹部'     ? 'selected' : ''}>幹部</option>
                    </select>
                    <input type="text" class="form-control form-control-sm" style="width:160px;${n.role !== '幹部' ? 'display:none;' : ''}"
                           id="title-input-${i}" placeholder="幹部職稱" maxlength="50"
                           value="${escAttr(n.officer_title)}"
                           oninput="updateTitle(${i}, this.value)">
                </div>
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" onclick="removeFromList(${i})">
                <i class="bi bi-trash"></i>
            </button>
        </div>`;

        hiddenHtml += `
        <input type="hidden" name="nominations[${i}][user_id]" value="${n.user_id}">
        <input type="hidden" id="hidden-title-${i}" name="nominations[${i}][officer_title]" value="${escAttr(n.officer_title)}">`;
    });

    container.innerHTML = listHtml;
    hidden.innerHTML = hiddenHtml;
}

function updateRole(i, role) {
    nominationList[i].role = role;
    const titleInput  = document.getElementById(`title-input-${i}`);
    const hiddenTitle = document.getElementById(`hidden-title-${i}`);
    if (role === '幹部') {
        titleInput.style.display = '';
        titleInput.focus();
    } else {
        titleInput.style.display = 'none';
        titleInput.value = '';
        nominationList[i].officer_title = '';
        if (hiddenTitle) hiddenTitle.value = '';
    }
}

function updateTitle(i, val) {
    nominationList[i].officer_title = val;
    const hiddenTitle = document.getElementById(`hidden-title-${i}`);
    if (hiddenTitle) hiddenTitle.value = val;
}

function removeFromList(i) {
    nominationList.splice(i, 1);
    renderList();
}

function validateSubmit() {
    for (let i = 0; i < nominationList.length; i++) {
        if (nominationList[i].role === '幹部' && !nominationList[i].officer_title.trim()) {
            alert(`第 ${i+1} 筆：幹部必須填寫職稱。`);
            document.getElementById(`title-input-${i}`)?.focus();
            return false;
        }
    }
    return confirm(`確定送出 ${nominationList.length} 筆提名申請？`);
}
</script>
</body>
</html>
