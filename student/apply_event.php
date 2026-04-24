<?php
$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $club_name = $_POST['club_name'];
    $event_name = $_POST['event_name'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $venue = $_POST['venue'];

    if ($venue == "請選擇場地") {
        $message = "❌ 請選擇場地！";
        $message_type = "error";
    }
    elseif ($start_time >= $end_time) {
        $message = "❌ 結束時間必須晚於開始時間！";
        $message_type = "error";
    } else {
        $message = "✅ {$club_name} 的「{$event_name}」申請成功！";
        $message_type = "success";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<title>活動申請</title>

<style>
body {
    margin: 0;
    font-family: "Segoe UI", sans-serif;
    background: #f5f6f8;
}

.sidebar {
    width: 220px;
    height: 100vh;
    background: white;
    position: fixed;
    border-right: 1px solid #ddd;
    padding: 20px;
}

.menu div {
    padding: 10px;
    margin-bottom: 10px;
    border-radius: 8px;
}
.active {
    background: black;
    color: white;
}

.main {
    margin-left: 240px;
    padding: 30px;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

input, textarea, select {
    width: 80%;
    padding: 8px;
    border-radius: 6px;
    border: 1px solid #ddd;
    margin-top: 5px;
    margin-bottom: 10px;
}

.row {
    display: flex;
    gap: 20px;
}

.col {
    flex: 1;
}

.equipment {
    display: flex;
    justify-content: space-between;
    border: 1px solid #eee;
    padding: 10px;
    border-radius: 10px;
    margin-bottom: 10px;
}

.counter button {
    width: 30px;
    height: 30px;
}

.counter input {
    width: 40px;
    text-align: center;
}

.message {
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 15px;
}
.success { background:#e6f4ea; color:#2e7d32; }
.error { background:#fdecea; color:#c62828; }

.actions {
    text-align: right;
}
.submit {
    background: black;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
}
</style>

<script>
let stocks = [10,10,10,10,10,10];

function changeQty(id, delta) {
    let input = document.getElementById("qty_" + id);
    let value = parseInt(input.value) + delta;

    let max = 10; // 固定庫存上限

    if (value < 0) value = 0;
    if (value > max) value = max;

    input.value = value;
}

// ⭐ 時間即時檢查
function checkTime() {
    let s = document.getElementById("start_time").value;
    let e = document.getElementById("end_time").value;

    let msg = document.getElementById("time_error");

    if (s && e && s >= e) {
        msg.innerText = "❌ 時間錯誤";
    } else {
        msg.innerText = "";
    }
}

// ⭐ 送出前檢查
function validateForm() {
    let venue = document.getElementById("venue").value;
    let s = document.getElementById("start_time").value;
    let e = document.getElementById("end_time").value;

    if (venue == "請選擇場地") {
        alert("請選擇場地！");
        return false;
    }

    if (s >= e) {
        alert("時間錯誤！");
        return false;
    }

    return true;
}
</script>

</head>

<body>

<div class="sidebar">
    <h2>課指組</h2>
    <div class="menu">
        <div>首頁</div>
        <div class="active">活動申請</div>
        <div>場地管理</div>
        <div>器材管理</div>
    </div>
</div>

<div class="main">

<h2>活動申請</h2>

<?php if($message): ?>
<div class="message <?= $message_type ?>">
<?= $message ?>
</div>
<?php endif; ?>

<form method="POST" onsubmit="return validateForm()">

<div class="card">
<h3>基本資料</h3>

活動名稱<br>
<input name="event_name" required><br>

社團名稱<br>
<input name="club_name" required><br>

活動日期<br>
<input type="date" name="date" required><br>

活動時間<br>
<input type="time" id="start_time" name="start_time" onchange="checkTime()">
～
<input type="time" id="end_time" name="end_time" onchange="checkTime()">
<div id="time_error" style="color:red;"></div>

</div>

<div class="row">

<div class="card col">
<h3>場地</h3>
<select name="venue" id="venue">
<option>請選擇場地</option>
<option>大禮堂</option>
<option>會議室A</option>
<option>活動中心</option>
</select>
</div>

<div class="card col">
<h3>器材</h3>

<?php
$eq = ["麥克風","投影機","音響","桌子","椅子","延長線"];
foreach($eq as $i => $name){
echo "
<div class='equipment'>
<div>
<b>$name</b><br>
<small>剩餘: 10</small>
</div>

<div class='counter'>
<button type='button' onclick='changeQty($i,-1)'>-</button>
<input id='qty_$i' name='qty[]' value='0' readonly>
<button type='button' onclick='changeQty($i,1)'>+</button>
</div>
</div>
";
}
?>

</div>

</div>

<div class="actions">
<button class="submit">送出申請</button>
</div>

</form>

</div>

</body>
</html>