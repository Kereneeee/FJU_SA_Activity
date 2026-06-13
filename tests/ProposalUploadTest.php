<?php
require_once __DIR__ . '/../includes/proposal_upload.php';

$cases = [];

$cases[] = ['name' => 'accepts pdf mime when finfo is unavailable', 'file' => ['name' => 'plan.pdf', 'type' => 'application/pdf', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK], 'expect' => true];
$cases[] = ['name' => 'rejects non pdf files', 'file' => ['name' => 'plan.docx', 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK], 'expect' => false];

foreach ($cases as $case) {
    try {
        $result = validate_proposal_upload($case['file']);
        if ($case['expect'] !== $result) {
            fwrite(STDERR, $case['name'] . " failed\n");
            exit(1);
        }
    } catch (Exception $e) {
        if ($case['expect']) {
            fwrite(STDERR, $case['name'] . " threw: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
}

echo "Proposal upload tests passed\n";
