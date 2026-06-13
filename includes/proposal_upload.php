<?php
function validate_proposal_upload(array $file): bool
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $name = $file['name'] ?? '';
    $tmpName = $file['tmp_name'] ?? '';
    $mimeType = $file['type'] ?? '';
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $allowedExtensions = ['pdf', 'doc', 'docx'];
    $allowedMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    if (in_array($extension, $allowedExtensions, true) && in_array($mimeType, $allowedMimeTypes, true)) {
        return true;
    }

    if ($extension === 'pdf' && function_exists('finfo_open')) {
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
