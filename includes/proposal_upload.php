<?php
function validate_proposal_upload(array $file): bool
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $name = $file['name'] ?? '';
    $tmpName = $file['tmp_name'] ?? '';
    $mimeType = $file['type'] ?? '';

    if ($mimeType === 'application/pdf' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf') {
        return true;
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo && $tmpName !== '' && is_file($tmpName)) {
            $detectedMime = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            if ($detectedMime === 'application/pdf') {
                return true;
            }
        }
    }

    return false;
}
